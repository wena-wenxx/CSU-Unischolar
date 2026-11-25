import re
from itertools import combinations

from rapidfuzz import fuzz

NAME_MATCH_MIN = 95
NAME_SIMILAR_MIN = 75
MIN_TEXT_CHARS = 30

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
    text = (text or "").upper()
    text = re.sub(r"[^A-Z0-9\s]", " ", text)
    return re.sub(r"\s+", " ", text).strip()


FORM_LABELS = {"FIRST", "MIDDLE", "LAST", "NAME", "GIVEN", "SURNAME", "FAMILY"}


def _as_initials(candidate: list, expected_words: list) -> list:
    initials = [w for w in expected_words if len(w) == 1]
    if not initials:
        return candidate
    out = list(candidate)
    for initial in initials:
        for i, word in enumerate(out):
            if len(word) > 1 and word not in expected_words and word.startswith(initial):
                out[i] = initial
                break
    return out


def best_name_match(expected_name: str, text: str):
    expected = normalize(expected_name)
    words = [w for w in normalize(text).split() if w not in FORM_LABELS and not w.isdigit()]
    if not expected or not words:
        return 0.0, ""

    expected_words = expected.split()
    k = len(expected_words)
    best_score, best_window = 0.0, ""
    for size in sorted({max(1, k - 1), k, k + 1, k + 2}):
        if size > len(words):
            continue
        for i in range(len(words) - size + 1):
            window = words[i:i + size]
            if size > k:
                candidates = [list(c) for c in combinations(window, k)]
            else:
                candidates = [window]
            for cand in candidates:
                score = fuzz.token_sort_ratio(expected, " ".join(_as_initials(cand, expected_words)))
                if score > best_score:
                    best_score, best_window = score, " ".join(window)
    return round(best_score, 2), best_window


def detect_kind(label: str):
    label = (label or "").lower()
    for kind, pattern, keywords, _shows_id, _name in DOC_KINDS:
        if re.search(pattern, label):
            return kind, keywords
    return None, []


def kind_shows_student_id(kind) -> bool:
    return any(k == kind and shows_id for k, _p, _w, shows_id, _n in DOC_KINDS)


def kind_name_mode(kind) -> str:
    for k, _p, _w, _id, mode in DOC_KINDS:
        if k == kind:
            return mode
    return "full"


def student_id_found(expected_id: str, text: str) -> bool:
    a = re.sub(r"[^A-Z0-9]", "", (expected_id or "").upper())
    b = re.sub(r"[^A-Z0-9]", "", (text or "").upper())
    return bool(a) and a in b


DATE_PATTERNS = (
    r"\b(?:January|February|March|April|May|June|July|August|September|October|November|December)"
    r"\s+\d{1,2},?\s+\d{4}\b",
    r"\b\d{1,2}[/-]\d{1,2}[/-]\d{4}\b",
    r"\b\d{4}[/-]\d{1,2}[/-]\d{1,2}\b",
    r"\b\d{1,2}\s+(?:January|February|March|April|May|June|July|August|September|October|November|December)\s+\d{4}\b",
    r"\b\d{1,2}(?:st|nd|rd|th|[^\s\d]{1,2})?\s+day\s+of\s+(?:January|February|March|April|May|June|July|August|September|October|November|December),?\s+\d{4}\b",
)

AUTHORITY_PATTERNS = (
    ("Philippine Statistics Authority", r"\b(?:PSA|Philippine Statistics Authority)\b"),
    ("Bureau of Internal Revenue", r"\b(?:BIR|Bureau of Internal Revenue)\b"),
    ("Civil Registrar", r"\b(?:Civil Registrar|Office of the Civil Registrar)\b"),
    ("Barangay", r"\bBarangay\b"),
)


def extract_structured_fields(text: str, kind: str | None) -> dict:
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
    found = []
    for a, b in re.findall(r"\b(20\d{2})\s*[-–/]\s*(20\d{2}|\d{2})\b", text or ""):
        a = int(a)
        b = int(b) if len(b) == 4 else 2000 + int(b)
        if b == a + 1:
            found.append(f"{a}-{b}")
    return sorted(set(found), reverse=True)

RELATION_WORDS = {"SON", "DAUGHTER", "CHILD", "GUARDIAN", "HIS", "HER", "OF", "THE", "MY", "AND", "OR"}


def child_named_on_parent_document(text: str):
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

PARENT_PART = re.compile(r"\b(MOTHER|FATHER|MAIDEN\s+NAME|INFORMANT)\b", re.I)


def validate_text(text: str, expected_name: str = "", expected_student_id: str = "",
                  document_label: str = "", expected_last_name: str = "",
                  current_school_year: str = "") -> dict:
    flags = []
    text = text or ""
    readable = len(text.strip()) >= MIN_TEXT_CHARS

    has_missing = False
    has_name_mismatch = False
    has_wrong = False

    kind, keywords = detect_kind(document_label)

    if not readable:
        has_missing = True
        flags.append("Very little text could be read from this file (blank, unclear or low quality scan).")

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

    id_found = None
    if expected_student_id.strip() and readable:
        id_found = student_id_found(expected_student_id, text)
        if not id_found and kind_shows_student_id(kind):
            has_missing = True
            flags.append("Student ID number was not found on the document.")

    years = school_years_in(text) if readable else []
    current = (current_school_year or "").strip()
    if kind == "registration" and current and years and current not in years:
        has_missing = True
        flags.append(f"This COR is for school year {years[0]}, not the current {current}. Ask for the current COR.")

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
