"""
Document validation rules for CSU UniScholar (ASSISTIVE ONLY).

This file never approves or rejects a student. It only produces FLAGS
that OAS staff then review. All keyword lists and thresholds below are
STARTING VALUES - to be confirmed/adjusted with real OAS sample documents.
"""
import re
from itertools import combinations

from rapidfuzz import fuzz

# ---- thresholds (0-100) -------------------------------------------------
NAME_MATCH_MIN = 95       # >= this  -> name matches (95 lets small OCR slips through but not a different surname)
NAME_SIMILAR_MIN = 75     # between  -> "similar but not exact" -> staff review
MIN_TEXT_CHARS = 30       # less than this -> document unreadable / empty

# ---- document kinds -> label patterns + words we expect to see ----------
# (kind, pattern matched against the requirement name, keywords expected in
#  the document text, does this document normally show the student ID?,
#  whose name is on it: "full" = the student's full name,
#                       "surname" = a parent's document, so only the
#                                   student's SURNAME is expected)
# Order matters: the first pattern that matches the requirement name wins.
#
# Only DISTINCTIVE words are listed. Generic words such as "CERTIFICATE",
# "PHILIPPINES", "BARANGAY" or "RESIDENT" appear on almost every Philippine
# certificate, so they would hide a wrong upload (e.g. a COR uploaded as a
# Birth Certificate). Short words that hide inside longer ones are avoided
# too ("BIR" is inside "BIRTH"). A document is flagged as "wrong" only when
# NONE of the keywords for its kind are found.
DOC_KINDS = [
    ("registration", r"registration|\bcor\b|enrol",
     ["REGISTRATION", "ENROLL", "UNITS", "SUBJECT", "SEMESTER"], True, "full"),
    ("grades", r"grade|report card|transcript|\btor\b",
     ["GRADE", "GRADES", "GWA", "AVERAGE", "SEMESTER", "SUBJECT", "UNITS", "REPORT"], True, "full"),
    ("id", r"\bid\b|identification",
     ["IDENTIFICATION", "VALID", "ID NO", "ID NUMBER", "SIGNATURE", "STUDENT"], True, "full"),
    ("birth_certificate", r"birth",
     ["BIRTH", "LIVE BIRTH", "PSA", "REGISTRY", "CIVIL REGISTRAR"], False, "full"),
    ("indigency", r"indigen",
     ["INDIGENCY", "INDIGENT", "LOW INCOME"], False, "full"),
    ("barangay_clearance", r"clearance",
     ["CLEARANCE"], False, "full"),
    ("good_moral", r"moral",
     ["GOOD MORAL", "MORAL CHARACTER"], False, "full"),
    ("income_tax_return", r"income tax|\bitr\b|tax return",
     ["INCOME TAX", "TAX RETURN", "TAXABLE INCOME", "INTERNAL REVENUE", "TAX DUE"], False, "surname"),
    ("recommendation_letter", r"recommend",
     ["RECOMMEND"], False, "full"),
]


def normalize(text: str) -> str:
    """UPPERCASE, remove punctuation, collapse spaces. 'Contiga, Wena' -> 'CONTIGA WENA'"""
    text = (text or "").upper()
    text = re.sub(r"[^A-Z0-9\s]", " ", text)
    return re.sub(r"\s+", " ", text).strip()


def best_name_match(expected_name: str, text: str):
    """
    Slide a small window of words across the document text and keep the best
    fuzzy score.
    - Word order does not matter ('CONTIGA WENA' == 'WENA CONTIGA').
    - A middle name on the document is allowed ('WENA ROSE CONTIGA' still
      matches 'Wena Contiga'): we also try dropping extra words from the window.
    Returns (score 0-100, best_window_text).
    """
    expected = normalize(expected_name)
    words = normalize(text).split()
    if not expected or not words:
        return 0.0, ""

    k = len(expected.split())
    best_score, best_window = 0.0, ""
    for size in sorted({max(1, k - 1), k, k + 1, k + 2}):
        if size > len(words):
            continue
        for i in range(len(words) - size + 1):
            window = words[i:i + size]
            # all ways to keep k words (in order); if the window is not longer than k, use it whole
            if size > k:
                candidates = [list(c) for c in combinations(window, k)]
            else:
                candidates = [window]
            for cand in candidates:
                score = fuzz.token_sort_ratio(expected, " ".join(cand))
                if score > best_score:
                    best_score, best_window = score, " ".join(window)
    return round(best_score, 2), best_window


def detect_kind(label: str):
    """Which kind of document does the requirement label describe?"""
    label = (label or "").lower()
    for kind, pattern, keywords, _shows_id, _name in DOC_KINDS:
        if re.search(pattern, label):
            return kind, keywords
    return None, []


def kind_shows_student_id(kind) -> bool:
    """Only school documents (COR, grades, ID) are expected to show the student ID."""
    return any(k == kind and shows_id for k, _p, _w, shows_id, _n in DOC_KINDS)


def kind_name_mode(kind) -> str:
    """'surname' for a parent's document (e.g. the parents' ITR), else 'full'."""
    for k, _p, _w, _id, mode in DOC_KINDS:
        if k == kind:
            return mode
    return "full"


def student_id_found(expected_id: str, text: str) -> bool:
    """Compare ignoring dashes/spaces: '2026-00101' also matches '202600101'."""
    a = re.sub(r"[^A-Z0-9]", "", (expected_id or "").upper())
    b = re.sub(r"[^A-Z0-9]", "", (text or "").upper())
    return bool(a) and a in b


def validate_text(text: str, expected_name: str = "", expected_student_id: str = "",
                  document_label: str = "", expected_last_name: str = "") -> dict:
    """Turn extracted text into flags. Returns the dict Laravel stores."""
    flags = []
    text = text or ""
    readable = len(text.strip()) >= MIN_TEXT_CHARS

    has_missing = False
    has_name_mismatch = False
    has_wrong = False

    kind, keywords = detect_kind(document_label)

    # 1) readable?
    if not readable:
        has_missing = True
        flags.append("Very little text could be read from this file (blank, unclear or low quality scan).")

    # 2) name check (a parent's document only needs to show the surname)
    name_score, name_window = (None, "")
    surname_only = kind_name_mode(kind) == "surname"
    if surname_only:
        target = (expected_last_name or "").strip() or (expected_name.strip().split() or [""])[-1]
    else:
        target = expected_name
    if target.strip() and readable:
        name_score, name_window = best_name_match(target, text)
        if name_score >= NAME_MATCH_MIN:
            pass
        elif surname_only:
            has_name_mismatch = True
            flags.append(f"Applicant's surname ({target.strip()}) was not found on this parent's document (best match {name_score}%).")
        elif name_score >= NAME_SIMILAR_MIN:
            has_name_mismatch = True
            flags.append(f"Name is similar but not exact (match {name_score}%). Found: '{name_window}'. Please check.")
        else:
            has_name_mismatch = True
            flags.append(f"Applicant name not found on the document (best match {name_score}%).")

    # 3) student id (only expected on registration / grades / ID documents)
    id_found = None
    if expected_student_id.strip() and readable:
        id_found = student_id_found(expected_student_id, text)
        if not id_found and kind_shows_student_id(kind):
            has_missing = True
            flags.append("Student ID number was not found on the document.")

    # 4) wrong document?
    hits = []
    if kind is not None and readable:
        norm = normalize(text)
        hits = [w for w in keywords if w in norm]
        if not hits:
            has_wrong = True
            flags.append(f"This does not look like the requested document ({document_label}).")

    return {
        "is_complete": not has_missing and not has_wrong,
        "has_name_mismatch": has_name_mismatch,
        "has_missing_information": has_missing,
        "has_wrong_document": has_wrong,
        "confidence_score": name_score,
        "flags": flags,
        "extracted_data": {
            "name_score": name_score,
            "best_name_window": name_window,
            "student_id_found": id_found,
            "document_kind": kind,
            "name_checked": "surname" if surname_only else "full name",
            "keywords_found": hits,
            "text_length": len(text.strip()),
        },
    }
