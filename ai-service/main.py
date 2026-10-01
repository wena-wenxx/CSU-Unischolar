from fastapi import FastAPI, UploadFile, File, Form, HTTPException
from fastapi.middleware.cors import CORSMiddleware

from pathlib import Path
from tempfile import NamedTemporaryFile

from rapidfuzz import fuzz

import os
import re
import json


app = FastAPI(
    title="CSU UniScholar AI Service",
    version="1.0.0"
)


app.add_middleware(
    CORSMiddleware,
    allow_origins=["*"],
    allow_credentials=True,
    allow_methods=["*"],
    allow_headers=["*"],
)


UPLOAD_DIR = Path("temp_uploads")
UPLOAD_DIR.mkdir(exist_ok=True)


@app.get("/")
def read_root():
    return {
        "message": "CSU UniScholar AI service is running",
        "status": "ready"
    }


def normalize_text(text: str) -> str:
    text = text.upper()
    text = re.sub(r"\s+", " ", text)
    return text.strip()


def normalize_name(name: str) -> str:
    name = normalize_text(name)
    name = re.sub(r"[^A-Z0-9 ]", "", name)
    return name


def extract_name_candidates(text: str):
    candidates = []

    lines = [
        line.strip()
        for line in text.splitlines()
        if line.strip()
    ]

    for line in lines:

        upper = line.upper()

        if any(keyword in upper for keyword in [
            "NAME",
            "STUDENT NAME",
            "FULL NAME",
            "APPLICANT"
        ]):

            parts = re.split(
                r":|-",
                line,
                maxsplit=1
            )

            if len(parts) == 2:
                value = parts[1].strip()

                if len(value.split()) >= 2:
                    candidates.append(value)

    return candidates


def check_name_match(
    expected_name: str,
    extracted_text: str
):
    expected = normalize_name(expected_name)

    candidates = extract_name_candidates(
        extracted_text
    )

    if not candidates:
        return {
            "matched": False,
            "score": 0,
            "candidate": None
        }

    best_score = 0
    best_candidate = None

    for candidate in candidates:

        candidate_normalized = normalize_name(
            candidate
        )

        score = fuzz.token_set_ratio(
            expected,
            candidate_normalized
        )

        if score > best_score:
            best_score = score
            best_candidate = candidate

    return {
        "matched": best_score >= 80,
        "score": best_score,
        "candidate": best_candidate
    }


def check_required_information(text: str):
    normalized = normalize_text(text)

    missing = []

    if len(normalized) < 20:
        missing.append(
            "Very little readable information was extracted."
        )

    return missing


def check_document_type(
    document_type: str,
    text: str
):
    normalized = normalize_text(text)

    keywords = {
        "COR": [
            "CERTIFICATE OF REGISTRATION",
            "REGISTRATION",
            "COURSE",
            "SUBJECT"
        ],
        "GRADES": [
            "GRADE",
            "GRADES",
            "SEMESTER",
            "SUBJECT"
        ],
        "BIRTH_CERTIFICATE": [
            "BIRTH",
            "CERTIFICATE",
            "PHILIPPINES"
        ],
        "VALID_ID": [
            "REPUBLIC",
            "IDENTIFICATION",
            "ID"
        ],
    }

    if not document_type:
        return {
            "possible_wrong_document": False,
            "matched_keywords": []
        }

    expected_keywords = keywords.get(
        document_type.upper(),
        []
    )

    if not expected_keywords:
        return {
            "possible_wrong_document": False,
            "matched_keywords": []
        }

    matched = [
        keyword
        for keyword in expected_keywords
        if keyword in normalized
    ]

    # If none of the expected keywords appear,
    # flag it for human review.
    wrong = len(matched) == 0

    return {
        "possible_wrong_document": wrong,
        "matched_keywords": matched
    }


def run_ocr(file_path: str):

    try:
        from paddleocr import PaddleOCR

        ocr = PaddleOCR(
            lang="en"
        )

        results = ocr.predict(
            file_path
        )

        collected_text = []

        for result in results:

            try:

                if hasattr(result, "json"):
                    data = result.json

                    if callable(data):
                        data = data()

                    if isinstance(data, str):
                        data = json.loads(data)

                    def walk(value):

                        if isinstance(value, dict):
                            for key, item in value.items():

                                if key in [
                                    "rec_texts",
                                    "text",
                                    "texts"
                                ]:
                                    if isinstance(item, list):
                                        for t in item:
                                            if isinstance(t, str):
                                                collected_text.append(t)

                                walk(item)

                        elif isinstance(value, list):
                            for item in value:
                                walk(item)

                    walk(data)

            except Exception:
                continue

        return "\n".join(collected_text)

    except Exception as error:

        raise RuntimeError(
            f"OCR processing failed: {str(error)}"
        )


@app.post("/validate-document")
async def validate_document(
    file: UploadFile = File(...),
    expected_name: str = Form(...),
    document_type: str = Form("")
):

    if not file.filename:
        raise HTTPException(
            status_code=400,
            detail="No file supplied."
        )

    suffix = Path(
        file.filename
    ).suffix.lower()

    if suffix not in [
        ".jpg",
        ".jpeg",
        ".png",
        ".pdf"
    ]:
        raise HTTPException(
            status_code=422,
            detail="Unsupported file type."
        )

    file_bytes = await file.read()

    if not file_bytes:
        raise HTTPException(
            status_code=422,
            detail="Uploaded file is empty."
        )

    with NamedTemporaryFile(
        delete=False,
        suffix=suffix,
        dir=UPLOAD_DIR
    ) as temp_file:

        temp_file.write(file_bytes)

        temp_path = temp_file.name

    try:

        extracted_text = run_ocr(
            temp_path
        )

        normalized_text = normalize_text(
            extracted_text
        )

        missing_information = (
            check_required_information(
                extracted_text
            )
        )

        name_result = check_name_match(
            expected_name,
            extracted_text
        )

        document_result = check_document_type(
            document_type,
            extracted_text
        )

        flags = []

        if missing_information:
            flags.extend(
                missing_information
            )

        if not name_result["matched"]:
            flags.append(
                "Possible student name mismatch."
            )

        if document_result[
            "possible_wrong_document"
        ]:
            flags.append(
                "Possible wrong document type."
            )

        is_complete = (
            len(missing_information) == 0
            and len(normalized_text) >= 20
        )

        confidence = (
            max(
                0,
                min(
                    100,
                    (
                        name_result["score"]
                        if name_result["candidate"]
                        else 50
                    )
                )
            )
        )

        return {
            "success": True,
            "is_complete": is_complete,
            "has_name_mismatch":
                not name_result["matched"],
            "has_missing_information":
                len(missing_information) > 0,
            "has_wrong_document":
                document_result[
                    "possible_wrong_document"
                ],
            "confidence_score":
                round(confidence, 2),
            "extracted_text":
                extracted_text,
            "extracted_data": {
                "expected_name":
                    expected_name,
                "detected_name":
                    name_result["candidate"],
                "document_type":
                    document_type,
                "matched_keywords":
                    document_result[
                        "matched_keywords"
                    ],
            },
            "flags": flags
        }

    finally:

        try:
            os.remove(temp_path)
        except OSError:
            pass