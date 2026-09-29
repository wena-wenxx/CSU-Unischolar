import os
import tempfile

from fastapi import FastAPI, File, Form, UploadFile

from ocr_engine import extract_text, ocr_status
from validation import validate_text

app = FastAPI(title="CSU UniScholar AI Service (assistive only)")


@app.get("/")
def read_root():
    return {"message": "AI service is running"}


@app.get("/ocr-status")
def get_ocr_status():
    """Open this in the browser to see if PaddleOCR loaded correctly."""
    return ocr_status()


@app.post("/validate-document")
def validate_document(
    file: UploadFile = File(...),
    expected_name: str = Form(""),
    expected_student_id: str = Form(""),
    document_label: str = Form(""),
):
    """
    Read the uploaded document and return FLAGS for OAS staff to review.
    It never approves or rejects anyone.
    """
    suffix = os.path.splitext(file.filename or "")[1] or ".bin"
    with tempfile.NamedTemporaryFile(suffix=suffix, delete=False) as tmp:
        tmp.write(file.file.read())
        tmp_path = tmp.name

    try:
        extracted = extract_text(tmp_path, file.filename or "")
    finally:
        os.remove(tmp_path)

    if extracted["ocr_status"] != "ok":
        return {
            "ocr_status": "failed",
            "error": extracted["error"],
            "extracted_text": "",
            "is_complete": False,
            "has_name_mismatch": False,
            "has_missing_information": False,
            "has_wrong_document": False,
            "confidence_score": None,
            "flags": ["Could not read this file automatically - needs manual review."],
            "extracted_data": {"engine": extracted["engine"], "error": extracted["error"]},
        }

    result = validate_text(
        extracted["text"],
        expected_name=expected_name,
        expected_student_id=expected_student_id,
        document_label=document_label,
    )
    result["ocr_status"] = "ok"
    result["extracted_text"] = extracted["text"]
    result["extracted_data"]["engine"] = extracted["engine"]
    return result
