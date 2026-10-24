"""
CSU UniScholar AI service (ASSISTIVE ONLY).

It reads an uploaded document and returns FLAGS for OAS staff to review.
It never approves or rejects anyone.

    ocr_engine.py  -> gets the text out of the file (PDF text or PaddleOCR)
    validation.py  -> turns that text into flags (name, student ID, document kind)
    list_reader.py -> reads an outside party's list of grantees (PDF, Word,
                      Excel, CSV, picture) and suggests which ScholarGuide
                      student each line is (staff confirm every match)
    fit_matcher.py -> how well each scholarship fits a student's field
                      (pretrained sentence embeddings, or TF-IDF without them)
    rule_reader.py -> reads a scholarship memo and suggests its eligibility
                      rules (local LLM through Ollama, or simple patterns);
                      staff confirm every value

Run (inside ai-service/, with the virtual environment active):
    uvicorn main:app --reload --port 8001
"""
import json
import os
from pathlib import Path
from tempfile import NamedTemporaryFile

from fastapi import Body, FastAPI, File, Form, HTTPException, UploadFile
from fastapi.middleware.cors import CORSMiddleware

from authenticity import file_notes
import fit_matcher
import rule_reader
from list_reader import LIST_EXTENSIONS, extract_and_match, table_rows
from ocr_engine import extract_text, ocr_status
from validation import validate_text

app = FastAPI(
    title="CSU UniScholar AI Service",
    version="1.2.0",
)

app.add_middleware(
    CORSMiddleware,
    allow_origins=["*"],
    allow_methods=["*"],
    allow_headers=["*"],
)

ALLOWED_EXTENSIONS = {".pdf", ".jpg", ".jpeg", ".png"}


@app.get("/")
def read_root():
    return {
        "message": "CSU UniScholar AI service is running",
        "status": "ready",
    }


@app.get("/ocr-status")
def get_ocr_status():
    """Is PaddleOCR installed and loaded? (Typed PDFs work even without it.)"""
    return ocr_status()


@app.post("/validate-document")
async def validate_document(
    file: UploadFile = File(...),
    expected_name: str = Form(...),
    expected_student_id: str = Form(""),
    expected_last_name: str = Form(""),
    document_label: str = Form(""),
    # Older name for document_label, still accepted.
    document_type: str = Form(""),
):
    if not file.filename:
        raise HTTPException(status_code=400, detail="No file supplied.")

    suffix = Path(file.filename).suffix.lower()

    if suffix not in ALLOWED_EXTENSIONS:
        raise HTTPException(status_code=422, detail="Unsupported file type.")

    file_bytes = await file.read()

    if not file_bytes:
        raise HTTPException(status_code=422, detail="Uploaded file is empty.")

    with NamedTemporaryFile(delete=False, suffix=suffix) as temp_file:
        temp_file.write(file_bytes)
        temp_path = temp_file.name

    try:
        extraction = extract_text(temp_path, file.filename)
        # "Possible edit" cautions from the file's hidden details (never a verdict).
        cautions = file_notes(temp_path, file.filename, file_bytes)
    finally:
        try:
            os.remove(temp_path)
        except OSError:
            pass

    label = document_label or document_type

    if extraction["ocr_status"] != "ok":
        # Could not read the file at all: send it to a human, do not guess.
        return {
            "success": True,
            "ocr_status": "failed",
            "engine": extraction["engine"],
            "is_complete": False,
            "has_name_mismatch": False,
            "has_missing_information": False,
            "has_wrong_document": False,
            "confidence_score": None,
            "extracted_text": "",
            "extracted_data": {"document_label": label, "authenticity_notes": cautions},
            "flags": [
                "The file could not be read automatically. Please check it manually. "
                f"({extraction['error']})"
            ],
        }

    result = validate_text(
        extraction["text"],
        expected_name=expected_name,
        expected_student_id=expected_student_id,
        document_label=label,
        expected_last_name=expected_last_name,
    )

    extracted_data = result["extracted_data"]
    extracted_data["document_label"] = label
    extracted_data["detected_name"] = extracted_data.get("best_name_window") or None
    extracted_data["authenticity_notes"] = cautions

    return {
        "success": True,
        "ocr_status": "ok",
        "engine": extraction["engine"],
        "is_complete": result["is_complete"],
        "has_name_mismatch": result["has_name_mismatch"],
        "has_missing_information": result["has_missing_information"],
        "has_wrong_document": result["has_wrong_document"],
        "confidence_score": result["confidence_score"],
        "extracted_text": extraction["text"],
        "extracted_data": extracted_data,
        "flags": result["flags"],
    }


@app.post("/extract-grantee-list")
async def extract_grantee_list(
    file: UploadFile = File(...),
    # JSON list of the students in ScholarGuide:
    # [{"id": 5, "student_id": "2024-10123", "first_name": "...", "middle_name": "...", "last_name": "..."}]
    students: str = Form("[]"),
    threshold: int = Form(80),
):
    """
    Reads a list of grantees (PDF, Word .docx, Excel .xlsx, CSV, PNG/JPG) and
    suggests a ScholarGuide student for each line. It only SUGGESTS: OAS staff
    confirm or correct every line before anything is saved.
    """
    if not file.filename:
        raise HTTPException(status_code=400, detail="No file supplied.")

    suffix = Path(file.filename).suffix.lower()
    if suffix in (".xls", ".doc"):
        raise HTTPException(status_code=422, detail="Old Office format. Open the file and save it as "
                            + (".xlsx (Excel Workbook)." if suffix == ".xls" else ".docx (Word Document)."))
    if suffix not in LIST_EXTENSIONS:
        raise HTTPException(status_code=422, detail="Unsupported file type. Use PDF, Word (.docx), Excel (.xlsx), CSV, PNG or JPG.")

    try:
        student_list = json.loads(students or "[]")
        if not isinstance(student_list, list):
            raise ValueError
    except ValueError:
        raise HTTPException(status_code=422, detail="students must be a JSON list.")

    threshold = max(50, min(100, int(threshold)))
    file_bytes = await file.read()
    if not file_bytes:
        raise HTTPException(status_code=422, detail="Uploaded file is empty.")

    with NamedTemporaryFile(delete=False, suffix=suffix) as temp_file:
        temp_file.write(file_bytes)
        temp_path = temp_file.name

    try:
        result = extract_and_match(temp_path, file.filename, student_list, threshold)
    except Exception as e:  # unreadable or damaged file: tell staff plainly
        raise HTTPException(status_code=422, detail=f"The file could not be read ({type(e).__name__}: {e}).")
    finally:
        try:
            os.remove(temp_path)
        except OSError:
            pass

    return {"success": True, "threshold": threshold, **result}


@app.post("/read-table")
async def read_table_endpoint(file: UploadFile = File(...)):
    """
    Reads any list file (PDF, Word .docx, Excel .xlsx, CSV, PNG/JPG) into rows
    of cells. Used by Approved Lists and Enrollment, where staff then choose
    which column is the Student ID.
    """
    if not file.filename:
        raise HTTPException(status_code=400, detail="No file supplied.")
    suffix = Path(file.filename).suffix.lower()
    if suffix in (".xls", ".doc"):
        raise HTTPException(status_code=422, detail="Old Office format. Open the file and save it as .xlsx or .docx.")
    if suffix not in LIST_EXTENSIONS:
        raise HTTPException(status_code=422, detail="Unsupported file type. Use PDF, Word (.docx), Excel (.xlsx), CSV, PNG or JPG.")
    file_bytes = await file.read()
    if not file_bytes:
        raise HTTPException(status_code=422, detail="Uploaded file is empty.")
    with NamedTemporaryFile(delete=False, suffix=suffix) as temp_file:
        temp_file.write(file_bytes)
        temp_path = temp_file.name
    try:
        result = table_rows(temp_path, file.filename)
    except Exception as e:
        raise HTTPException(status_code=422, detail=f"The file could not be read ({type(e).__name__}: {e}).")
    finally:
        try:
            os.remove(temp_path)
        except OSError:
            pass
    return {"success": True, **result}


@app.get("/fit-status")
def fit_status():
    """Which text-matching method is used: the embedding model, or TF-IDF when it is not installed."""
    return fit_matcher.status()


@app.post("/fit-scores")
def fit_scores(payload: dict = Body(...)):
    """
    Field fit of every student to every scholarship (AI Recommendations).
    Body: {"students": [{"id", "text"}], "programs": [{"id", "text"}]}
    It only ranks and explains; the eligibility rules stay in Laravel.
    """
    students = payload.get("students") or []
    programs = payload.get("programs") or []
    if not students or not programs:
        raise HTTPException(status_code=422, detail="Send at least one student and one program.")
    if len(students) * len(programs) > 200000:
        raise HTTPException(status_code=422, detail="Too many pairs in one request; send fewer students.")
    return {"success": True, **fit_matcher.score(students, programs)}


@app.post("/suggest-rules")
async def suggest_rules(
    file: UploadFile | None = File(None),
    text: str = Form(""),
    options: str = Form("{}"),
    use_llm: bool = Form(True),
):
    """
    Reads a scholarship memo (pasted text, or a PDF / Word / TXT / picture file)
    and suggests its eligibility rules, each with the sentence it came from.
    Staff check and apply them; nothing is saved here.
    """
    try:
        choices = json.loads(options or "{}")
    except json.JSONDecodeError:
        choices = {}

    memo = text or ""
    if file is not None and file.filename:
        suffix = Path(file.filename).suffix.lower()
        if suffix not in {".pdf", ".docx", ".txt", ".png", ".jpg", ".jpeg"}:
            raise HTTPException(status_code=422, detail="Use a PDF, Word (.docx), TXT, PNG or JPG file.")
        file_bytes = await file.read()
        with NamedTemporaryFile(delete=False, suffix=suffix) as temp_file:
            temp_file.write(file_bytes)
            temp_path = temp_file.name
        try:
            memo = (memo + "\n" + rule_reader.read_file_text(temp_path, file.filename)).strip()
        except Exception as e:
            raise HTTPException(status_code=422, detail=f"The file could not be read ({type(e).__name__}: {e}).")
        finally:
            try:
                os.remove(temp_path)
            except OSError:
                pass

    try:
        result = rule_reader.suggest(memo, choices, use_llm=use_llm)
    except ValueError as e:
        raise HTTPException(status_code=422, detail=str(e))
    return {"success": True, "characters": len(memo), **result}
