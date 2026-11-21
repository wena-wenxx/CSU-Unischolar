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
    """UPPERCASE, remove punctuation, collapse spaces. 'Navarro, Juan' -> 'NAVARRO JUAN'"""
    text = (text or "").upper()
    text = re.sub(r"[^A-Z0-9\s]", " ", text)
    return re.sub(r"\s+", " ", text).strip()


FORM_LABELS = {"FIRST", "MIDDLE", "LAST", "NAME", "GIVEN", "SURNAME", "FAMILY"}


def best_name_match(expected_name: str, text: str):
    """
    Slide a small window of words across the document text and keep the best
    fuzzy score.
    - Word order does not matter ('NAVARRO JUAN' == 'JUAN NAVARRO').
    - A middle name on the document is allowed ('JUAN LOPEZ NAVARRO' still
      matches 'Juan Navarro'): we also try dropping extra words from the window.
    Returns (score 0-100, best_window_text).
    """
    expected = normalize(expected_name)
    # Form labels between the parts of a name ("NAME (First) MARIA (Middle) CRUZ (Last) SANTOS" on a
    # PSA birth certificate) and item numbers are skipped, so the name parts sit next to each other.
    words = [w for w in normalize(text).split() if w not in FORM_LABELS and not w.isdigit()]
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


DATE_PATTERNS = (
    r"\b(?:January|February|March|April|May|June|July|August|September|October|November|December)"
    r"\s+\d{1,2},?\s+\d{4}\b",
    r"\b\d{1,2}[/-]\d{1,2}[/-]\d{4}\b",
    r"\b\d{4}[/-]\d{1,2}[/-]\d{1,2}\b",
    r"\b\d{1,2}\s+(?:January|February|March|April|May|June|July|August|September|October|November|December)\s+\d{4}\b",
    # "this 7th day of July, 2022" (barangay, BIR and school certifications)
    r"\b\d{1,2}(?:st|nd|rd|th|[^\s\d]{1,2})?\s+day\s+of\s+(?:January|February|March|April|May|June|July|August|September|October|November|December),?\s+\d{4}\b",
)

AUTHORITY_PATTERNS = (
    ("Philippine Statistics Authority", r"\b(?:PSA|Philippine Statistics Authority)\b"),
    ("Bureau of Internal Revenue", r"\b(?:BIR|Bureau of Internal Revenue)\b"),
    ("Civil Registrar", r"\b(?:Civil Registrar|Office of the Civil Registrar)\b"),
    ("Barangay", r"\bBarangay\b"),
)


def extract_structured_fields(text: str, kind: str | None) -> dict:
    """Best-effort extraction; values remain reviewable and are never used as an approval verdict."""
    source = text or ""
    dates = []
    for pattern in DATE_PATTERNS:
        dates.extend(re.findall(pattern, source, re.I))
    dates = list(dict.fromkeys(dates))

    authorities = []
    for label, pattern in AUTHORITY_PATTERNS:
        if re.search(pattern, source, re.I):
            authorities.append(label)

    income_match = re.search(
        r"\b(?:taxable income|annual income|gross income|family income|income of)\b[^0-9]{0,15}(PHP|₱|P)?\s*([\d,]+(?:\.\d{2})?)",
        source,
        re.I,
    )
    document_date = dates[0] if dates else None
    return {
        "document_kind": kind,
        "dates": dates,
        "document_date": document_date,
        "issuing_authorities": authorities,
        "extracted_family_income": (
            income_match.group(2).replace(",", "") if income_match else None
        ),
    }


def school_years_in(text: str) -> list:
    """School years written on a document: '2024-2025', 'AY 2024 - 2025', 'SY 2024-25'. Newest first."""
    found = []
    for a, b in re.findall(r"\b(20\d{2})\s*[-–/]\s*(20\d{2}|\d{2})\b", text or ""):
        a = int(a)
        b = int(b) if len(b) == 4 else 2000 + int(b)
        if b == a + 1:
            found.append(f"{a}-{b}")
    return sorted(set(found), reverse=True)


# A parent's document (BIR certification, ITR) often names the child it was issued for:
# "... for scholarship purposes of his/her son/daughter GLESLIMIE B. BINAG".
RELATION_WORDS = {"SON", "DAUGHTER", "CHILD", "GUARDIAN", "HIS", "HER", "OF", "THE", "MY", "AND", "OR"}


def child_named_on_parent_document(text: str):
    """The child's name written on a parent's document, or None. Used to spot a sibling's copy."""
    words = normalize(text).split()
    for i, word in enumerate(words):
        if word not in {"SON", "DAUGHTER", "CHILD", "GUARDIAN"}:
            continue
        j = i + 1
        while j < len(words) and words[j] in RELATION_WORDS:
            j += 1
        name = []
        for w in words[j:j + 4]:
            if not w.isalpha() or w in {"DONE", "THIS", "ISSUED", "GIVEN", "FOR", "WHO", "IS", "A"}:
                break
            name.append(w)
        if len([w for w in name if len(w) > 1]) >= 2:
            return " ".join(name)
    return None


# On a birth certificate the parents' names come after these words; the child's own name comes first.
PARENT_PART = re.compile(r"\b(MOTHER|FATHER|MAIDEN\s+NAME|INFORMANT)\b", re.I)


def validate_text(text: str, expected_name: str = "", expected_student_id: str = "",
                  document_label: str = "", expected_last_name: str = "",
                  current_school_year: str = "") -> dict:
    """
    Turn extracted text into flags. Returns the dict Laravel stores.
    current_school_year (e.g. "2026-2027"): a COR for another school year is flagged.
    """
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
    parents_only = False
    if target.strip() and readable:
        name_score, name_window = best_name_match(target, text)
        if kind == "birth_certificate" and name_score >= NAME_MATCH_MIN:
            # The name must be in the CHILD's part, not built from the parents' names further down.
            cut = PARENT_PART.search(text)
            if cut and cut.start() > 0:
                child_score, child_window = best_name_match(target, text[:cut.start()])
                if child_score < NAME_MATCH_MIN:
                    parents_only = True
                    name_score, name_window = child_score, child_window
        if parents_only:
            has_name_mismatch = True
            flags.append("The applicant's name was not found as the child's name on this birth certificate "
                         f"(best match {name_score}%); it appears only in the parents' part. Check whose certificate this is.")
        elif surname_only and name_score >= NAME_MATCH_MIN:
            # The surname is there. If the document also names the child it was issued for, it must be
            # the applicant (a brother's or sister's copy has the same surname).
            child = child_named_on_parent_document(text)
            if child and best_name_match(expected_name, child)[0] < NAME_SIMILAR_MIN:
                has_name_mismatch = True
                flags.append(f"This parent's document was issued for '{child}', not for the applicant. "
                             "It may belong to a brother or sister; check it.")
        elif name_score >= NAME_MATCH_MIN:
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

    # 3b) a COR must be for the current school year
    years = school_years_in(text) if readable else []
    current = (current_school_year or "").strip()
    if kind == "registration" and current and years and current not in years:
        has_missing = True
        flags.append(f"This COR is for school year {years[0]}, not the current {current}. Ask for the current COR.")

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
            "school_years": years,
            **extract_structured_fields(text, kind),
            "review_status": (
                "needs_manual_review"
                if has_missing or has_wrong or has_name_mismatch
                else "no_obvious_issues"
            ),
        },
    }
