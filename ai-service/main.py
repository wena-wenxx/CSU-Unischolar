import hashlib
import json
import logging
import os
import time
from collections import OrderedDict
from pathlib import Path
from tempfile import NamedTemporaryFile

from fastapi import Body, FastAPI, File, Form, HTTPException, UploadFile
from fastapi.middleware.cors import CORSMiddleware
from starlette.concurrency import run_in_threadpool

from authenticity import file_notes
import fit_matcher
import rule_reader
from list_reader import LIST_EXTENSIONS, extract_and_match, table_rows
import ocr_engine
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

log = logging.getLogger("uvicorn.error")

_RESULTS = OrderedDict()
_RESULTS_MAX = 64


@app.on_event("startup")
def _load_ocr_early():
    ocr_engine.warm_up()


def _cached(key):
    if key in _RESULTS:
        _RESULTS.move_to_end(key)
        return json.loads(_RESULTS[key])
    return None


def _remember(key, value):
    _RESULTS[key] = json.dumps(value)
    _RESULTS.move_to_end(key)
    while len(_RESULTS) > _RESULTS_MAX:
        _RESULTS.popitem(last=False)


@app.get("/")
def read_root():
    return {
        "message": "CSU UniScholar AI service is running",
        "status": "ready",
    }


@app.get("/health")
def health():
    import ocr_engine
    import rule_reader

    engine = ocr_engine.ocr_engine_name()
    try:
        model = rule_reader.ollama_model(timeout=2)
    except Exception:
        model = None
    return {
        "service": "running",
        "ocr_engine": engine or "none (scanned files and photos cannot be read; install Tesseract or PaddleOCR)",
        "paddleocr": ocr_engine.ocr_status(),
        "tesseract_program": ocr_engine._tesseract_cmd(),
        "text_matching": fit_matcher.status(),
        "memo_reader": f"local language model {model}" if model else "pattern reader (Ollama not running; optional)",
    }


@app.get("/ocr-status")
def get_ocr_status():
    return ocr_status()


@app.post("/validate-document")
async def validate_document(
    file: UploadFile = File(...),
    expected_name: str = Form(...),
    expected_student_id: str = Form(""),
    expected_last_name: str = Form(""),
    document_label: str = Form(""),
    document_type: str = Form(""),
    current_school_year: str = Form(""),
):
    if not file.filename:
        raise HTTPException(status_code=400, detail="No file supplied.")

    suffix = Path(file.filename).suffix.lower()

    if suffix not in ALLOWED_EXTENSIONS:
        raise HTTPException(status_code=422, detail="Unsupported file type.")

    file_bytes = await file.read()

    if not file_bytes:
        raise HTTPException(status_code=422, detail="Uploaded file is empty.")

    label = document_label or document_type
    started = time.monotonic()
    key = hashlib.sha256(file_bytes).hexdigest() + "|" + json.dumps(
        [expected_name, expected_student_id, expected_last_name, label, current_school_year])
    cached = _cached(key)
    if cached is not None:
        cached["cached"] = True
        cached["elapsed_seconds"] = round(time.monotonic() - started, 2)
        log.info("validate-document %s: same file checked before, answered from memory", file.filename)
        return cached

    result = await run_in_threadpool(
        _check_file, file_bytes, suffix, file.filename, label, expected_name, expected_student_id,
        expected_last_name, current_school_year,
    )
    result["elapsed_seconds"] = round(time.monotonic() - started, 2)
    log.info("validate-document %s: %s in %.1f s (%s)", file.filename, result.get("ocr_status"),
             result["elapsed_seconds"], result.get("engine"))
    if result.get("ocr_status") == "ok":
        _remember(key, result)
    return result


def _check_file(file_bytes, suffix, filename, label, expected_name, expected_student_id, expected_last_name,
                current_school_year):
    with NamedTemporaryFile(delete=False, suffix=suffix) as temp_file:
        temp_file.write(file_bytes)
        temp_path = temp_file.name

    try:
        extraction = extract_text(temp_path, filename)
        cautions = file_notes(temp_path, filename, file_bytes)
    finally:
        try:
            os.remove(temp_path)
        except OSError:
            pass

    if extraction["ocr_status"] != "ok":
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
        current_school_year=current_school_year,
    )

    extracted_data = result["extracted_data"]
    extracted_data["document_label"] = label
    extracted_data["detected_name"] = extracted_data.get("best_name_window") or None
    extracted_data["authenticity_notes"] = cautions
    if extraction.get("note"):
        extracted_data["reading_note"] = extraction["note"]
        result["flags"] = list(result["flags"]) + [extraction["note"]]

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
    students: str = Form("[]"),
    threshold: int = Form(80),
):
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
        result = await run_in_threadpool(extract_and_match, temp_path, file.filename, student_list, threshold)
    except Exception as e:
        raise HTTPException(status_code=422, detail=f"The file could not be read ({type(e).__name__}: {e}).")
    finally:
        try:
            os.remove(temp_path)
        except OSError:
            pass

    return {"success": True, "threshold": threshold, **result}


@app.post("/read-table")
async def read_table_endpoint(file: UploadFile = File(...)):
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
    started = time.monotonic()
    key = "table|" + hashlib.sha256(file_bytes).hexdigest() + "|" + suffix
    cached = _cached(key)
    if cached is not None:
        cached["elapsed_seconds"] = round(time.monotonic() - started, 2)
        cached["cached"] = True
        return {"success": True, **cached}
    with NamedTemporaryFile(delete=False, suffix=suffix) as temp_file:
        temp_file.write(file_bytes)
        temp_path = temp_file.name
    try:
        result = await run_in_threadpool(table_rows, temp_path, file.filename)
        try:
            _remember(key, result)
        except (TypeError, ValueError):
            pass
        result["elapsed_seconds"] = round(time.monotonic() - started, 2)
    except Exception as e:
        text = str(e)
        if "password" in text.lower() or "encrypt" in text.lower():
            detail = "The PDF is password-protected. Ask for a copy without a password."
        elif suffix == ".pdf":
            detail = ("The PDF could not be opened: it may be damaged, or it is not really a PDF. Open it on your "
                      f"computer to check, or ask for a new copy. ({type(e).__name__}: {text[:150]})")
        else:
            detail = f"The file could not be read ({type(e).__name__}: {text[:150]})."
        raise HTTPException(status_code=422, detail=detail)
    finally:
        try:
            os.remove(temp_path)
        except OSError:
            pass
    return {"success": True, **result}


@app.get("/fit-status")
def fit_status():
    return fit_matcher.status()


@app.post("/fit-scores")
def fit_scores(payload: dict = Body(...)):
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
            memo = (memo + "\n" + await run_in_threadpool(rule_reader.read_file_text, temp_path, file.filename)).strip()
        except Exception as e:
            raise HTTPException(status_code=422, detail=f"The file could not be read ({type(e).__name__}: {e}).")
        finally:
            try:
                os.remove(temp_path)
            except OSError:
                pass

    try:
        result = await run_in_threadpool(rule_reader.suggest, memo, choices, use_llm=use_llm)
    except ValueError as e:
        raise HTTPException(status_code=422, detail=str(e))
    return {"success": True, "characters": len(memo), **result}
