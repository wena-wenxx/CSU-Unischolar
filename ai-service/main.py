"""
CSU UniScholar AI service (ASSISTIVE ONLY).

It reads an uploaded document and returns FLAGS for OAS staff to review.
It never approves or rejects anyone.

    ocr_engine.py  -> gets the text out of the file (PDF text or PaddleOCR)
    validation.py  -> turns that text into flags (name, student ID, document kind)

Run (inside ai-service/, with the virtual environment active):
    uvicorn main:app --reload --port 8001
"""
import os
from pathlib import Path
from tempfile import NamedTemporaryFile

from fastapi import FastAPI, File, Form, HTTPException, UploadFile
from fastapi.middleware.cors import CORSMiddleware

from ocr_engine import extract_text, ocr_status
from validation import validate_text

app = FastAPI(
    title="CSU UniScholar AI Service",
    version="1.1.0",
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
            "extracted_data": {"document_label": label},
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
