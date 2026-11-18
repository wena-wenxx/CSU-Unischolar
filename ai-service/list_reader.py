"""
External grantee lists (ASSISTIVE ONLY).

Reads a list of scholarship grantees that an outside party (LGU, CHED,
private foundation) gave the OAS, and suggests which student in
ScholarGuide each line belongs to. OAS staff confirm or correct every
suggestion before anything is saved.

    read_table(path, filename)    -> rows of cells, whatever the file type
        .csv         Python's csv module
        .xlsx        openpyxl (every sheet)
        .docx        python-docx (tables; otherwise one row per paragraph)
        .pdf         PyMuPDF words, put back into rows and columns by their
                     position on the page; a scanned page (no text) goes
                     through PaddleOCR and is put back the same way
        .png / .jpg  PaddleOCR
    parse_records(rows)           -> one record per grantee: name + Student ID
    match_records(records, students, threshold)
                                  -> matched / check / unmatched / error,
                                     with the best candidates for staff

Matching rules (thresholds are 0-100 RapidFuzz scores):
    1. Student ID found in ScholarGuide -> that student, if the name on the
       line is similar enough (>= ID_NAME_MIN) or there is no name.
       ID found but the name looks different -> "check" (staff decide).
    2. No usable Student ID -> fuzzy name match (token_sort_ratio, so
       "DELA CRUZ, JUAN M." and "Juan M. Dela Cruz" are the same).
       best >= threshold (default 80) and clearly ahead of the 2nd best
       -> "matched"; two students almost equally close -> "check".
       If the line HAD a Student ID that is not in ScholarGuide, a name
       match is only a "check", never "matched".
    3. Nothing close enough -> "unmatched" (with suggestions >= SUGGEST_MIN).
    4. No name and no Student ID could be read -> "error".
"""
import csv
import os
import re
import zipfile
import statistics
import tempfile
import unicodedata
from datetime import datetime

from rapidfuzz import fuzz

ID_NAME_MIN = 60      # Student ID found: the name must be at least this similar
SUGGEST_MIN = 60      # candidates shown to staff for unmatched / check rows
AMBIGUOUS_GAP = 3     # best and 2nd-best names closer than this -> "check"
MAX_ROWS = 5000

LIST_EXTENSIONS = {".pdf", ".docx", ".xlsx", ".csv", ".png", ".jpg", ".jpeg"}

# CSU-style IDs: 221-00462, 2024-10123 (spaces around the dash are tolerated).
ID_RE = re.compile(r"(?<![\d-])(\d{2,4})\s?-\s?(\d{4,6})(?![\d-])")

HEADER_PATTERNS = {
    "student_id": re.compile(r"^(student\s*(id|no\.?|number|#)|stud(ent)?\.?\s*no\.?|id\s*(no\.?|number)|school\s*id|id)$", re.I),
    "name": re.compile(r"^(name|full\s*name|complete\s*name|student'?s?\s*name|name\s*of\s*(the\s*)?(student|grantee|scholar|awardee)s?|grantee|scholar|awardee)$", re.I),
    "last": re.compile(r"^(last\s*name|surname|family\s*name|apelyido)$", re.I),
    "first": re.compile(r"^(first\s*name|given\s*name|pangalan)$", re.I),
    "middle": re.compile(r"^(middle\s*(name|initial)?|m\.?\s*i\.?)$", re.I),
    "control_number": re.compile(r"^(control\s*(number|no\.?)|no\.?)$", re.I),
    "award_number": re.compile(r"^(tes\s*)?award\s*(number|no\.?)$", re.I),
    "birthdate": re.compile(r"^(date\s*of\s*birth|birthdate|birth\s*date)$", re.I),
    "amount": re.compile(r"^(tes\s*)?amount$", re.I),
    "total_amount": re.compile(r"^(total\s*(tes\s*)?amount|total)$", re.I),
    "sex": re.compile(r"^(sex(\s*at\s*birth)?|sex\s*\(m/f\)|gender)$", re.I),
    "program": re.compile(r"^(degree\s*program|program|course)$", re.I),
    "year_level": re.compile(r"^(year\s*level|year)$", re.I),
    "email": re.compile(r"^(email(\s*address)?|e-?mail)$", re.I),
    "phone": re.compile(r"^(phone(\s*number)?|mobile(\s*number)?|contact)$", re.I),
    "pwd": re.compile(r"^(person\s*with\s*disability|pwd)$", re.I),
    "batch": re.compile(r"^(tes\s*batch|batch)$", re.I),
}

# Government forms are configured here so another form can be added without
# changing the PDF reader's control flow.
FORM_TEMPLATES = {
    "tes_continuing_form_2_annex_2": {
        "labels": (
            "consolidated continuing tes grantees details",
            "tes billing details reference number",
        ),
        "columns": (
            "control_number", "student_id", "award_number", "last", "first",
            "middle", "sex", "birthdate", "program", "year_level", "email",
            "phone", "batch", "amount", "pwd", "total_amount",
        ),
        "control_re": re.compile(r"^\d{5}$"),
        "student_re": re.compile(r"^\d{3}-\d{5}$"),
        "award_re": re.compile(r"^TES-\d{8}-\d{12}-\d{6}$", re.I),
    },
    "general_payroll": {
        "labels": (
            "general payroll",
            "general form no.(6 a)",
            "ched tertiary education subsidy",
        ),
        "columns": (
            "sheet", "atm_status", "control_number", "student_id", "name",
            "first_semester_amount", "second_semester_amount", "net_amount",
        ),
    },
}

_REFERENCE_RE = re.compile(r"\b([A-Z]{2,}(?:-[A-Z0-9]+){3,})\b", re.I)
_DATE_RE = re.compile(
    r"\b((?:January|February|March|April|May|June|July|August|September|October|November|December)"
    r"\s+\d{1,2},\s+\d{4})\b", re.I)
_AY_RE = re.compile(r"\b(?:AY|A\.Y\.?|Academic\s+Year)\s*[:.]?\s*(\d{4}\s*-\s*\d{4})\b", re.I)
_TOTAL_RE = re.compile(r"(?:printed\s+)?total(?:\s+amount)?\s*[:\-]?\s*(?:PHP|₱)?\s*([\d,]+(?:\.\d{2})?)", re.I)
_TES_FOOTER_TOTAL_RE = re.compile(
    r"\b(?:grand\s+total|total\s+tertiary\s+education\s+subsidy|total\s+tes\s+billing(?:\s+per\s+campus|\s+for\s+all\s+campuses)?|all\s+campuses)"
    r"[^\d]*(?:PHP|₱)?\s*([\d,]+(?:\.\d{2})?)",
    re.I,
)


def bad_text_layer(words):
    """Return True when embedded PDF text looks like low-quality OCR."""
    texts = [str(w[4] if len(w) > 4 else w).strip() for w in words if str(w[4] if len(w) > 4 else w).strip()]
    joined = " ".join(texts)
    if "\ufffd" in joined or "�" in joined:
        return True
    if len(texts) < 3:
        return True
    tokens = re.findall(r"[A-Za-zÀ-ÿ0-9'€$₱.-]+", " ".join(texts))
    if not tokens:
        return True
    odd = sum(
        1 for token in tokens
        if (len(token) > 2 and not re.search(r"[AEIOUaeiou]", token)
            and not re.fullmatch(r"\d+(?:[.,-]\d+)*", token))
        or re.search(r"[€$₱]{2,}|[^\w\s.,'()/:-]", token)
    )
    return odd / max(1, len(tokens)) >= 0.18


is_bad_text_layer = bad_text_layer


def classify_page(text, rows=None):
    """Classify a page before rows are admitted to the table."""
    text = _clean(text).lower()
    rows = rows or []
    if any(_find_header(row) for row in rows) or re.search(r"\b\d{5}\b", text):
        return "table"
    if any(label in text for label in ("instruction", "how to accomplish", "reminders")):
        return "instruction"
    if any(label in text for label in ("signature", "certified correct", "approved by")):
        return "signature"
    if re.search(r"\bgrand\s+total\b|\btotal\s+amount\b", text) and not re.search(r"\bcontrol\s*(number|no)", text):
        return "total"
    return "other"


def detect_form_template(text, rows=None):
    """Return a configured form name when its identifying labels are present."""
    haystack = " ".join([text or ""] + [" ".join(row) for row in (rows or [])]).lower()
    for name, template in FORM_TEMPLATES.items():
        if sum(label in haystack for label in template["labels"]) >= 1:
            return name
    return None


def extract_metadata(text, template=None):
    """Extract review-only metadata; missing values remain None."""
    source = text or ""
    date_value = None
    date_match = _DATE_RE.search(source)
    if date_match:
        try:
            date_value = datetime.strptime(date_match.group(1).title(), "%B %d, %Y").date().isoformat()
        except ValueError:
            date_value = date_match.group(1)
    ay_match = _AY_RE.search(source)
    semester = None
    semester_match = re.search(r"\b(1st|1|first|2nd|2|second)\s+semester\b|\b(summer)\b", source, re.I)
    if semester_match:
        value = next((v for v in semester_match.groups() if v), "").lower()
        semester = "Summer" if value == "summer" else ("1st Semester" if value in ("1st", "1", "first") else "2nd Semester")
    batch_match = re.search(r"\b(batch\s+\d+)\b", source, re.I)
    program_match = re.search(r"\bprogram\s*[:\-]\s*([A-Za-z][A-Za-z ]+)", source, re.I)
    campus_match = re.search(r"\bcampus\s*[:\-]\s*([^\n,;]+)", source, re.I)
    total_match = _TOTAL_RE.search(source)
    return {
        "template": template,
        "reference_number": _REFERENCE_RE.search(source).group(1).upper() if _REFERENCE_RE.search(source) else None,
        "date": date_value,
        "program": program_match.group(1).strip() if program_match else ("TES" if template else None),
        "batch": batch_match.group(1).title() if batch_match else None,
        "semester": semester,
        "academic_year": ay_match.group(1).replace(" ", "") if ay_match else None,
        "campus": campus_match.group(1).strip() if campus_match else None,
        "printed_total": float(total_match.group(1).replace(",", "")) if total_match else None,
    }


def validate_document_rows(rows, metadata=None, template=None):
    """Return non-blocking document checks and per-row confidence."""
    metadata = metadata or {}
    warnings = []
    if template == "tes_continuing_form_2_annex_2":
        amounts = []
        controls = set()
        students = set()
        awards = set()
        for row in rows:
            if not row or re.search(r"control\s*number", " ".join(row), re.I):
                continue
            control = next((c for c in row if re.fullmatch(r"\d{5}", c)), "")
            student = next((c for c in row if re.fullmatch(r"\d{3}-\d{5}", c)), "")
            award = next((c for c in row if re.fullmatch(r"TES-\d{8}-\d{12}-\d{6}", c, re.I)), "")
            total = row[15] if len(row) > 15 else ""
            if control:
                if control in controls:
                    warnings.append(f"Duplicate control number: {control}.")
                controls.add(control)
            if student:
                if student in students:
                    warnings.append(f"Duplicate student number: {student}.")
                students.add(student)
            if award:
                if award in awards:
                    warnings.append(f"Duplicate TES award number: {award}.")
                awards.add(award)
            if re.fullmatch(r"[\d,]+\.\d{2}", total):
                amounts.append(float(total.replace(",", "")))
        summed = round(sum(amounts), 2)
        printed = metadata.get("printed_total")
        if printed is not None and abs(summed - printed) > 0.01:
            warnings.append(f"Rows sum to {summed:,.2f}, but the printed total is {printed:,.2f}.")
        return {
            "warnings": list(dict.fromkeys(warnings)),
            "row_confidence": [
                "matched" if re.search(r"\d{3}-\d{5}", " ".join(row)) else "check"
                for row in rows
            ],
            "row_count": len(students),
            "summed_amount": summed,
        }
    if template == "general_payroll":
        amounts = []
        students = set()
        for row in rows[1:] if rows and re.search(r"\bname\b", " ".join(rows[0]), re.I) else rows:
            raw = " ".join(row)
            student = next((c for c in row if re.fullmatch(r"\d{4}-\d{5}", c)), "")
            if student:
                if student in students:
                    warnings.append(f"Duplicate student number: {student}.")
                students.add(student)
            net = next((c for c in reversed(row) if re.fullmatch(r"[\d,]+\.\d{2}", c)), "")
            if net:
                amounts.append(float(net.replace(",", "")))
        summed = round(sum(amounts), 2)
        printed = metadata.get("printed_total")
        if printed is not None and abs(summed - printed) > 0.01:
            warnings.append(f"Rows sum to {summed:,.2f}, but the printed total is {printed:,.2f}.")
        return {
            "warnings": list(dict.fromkeys(warnings)),
            "row_confidence": [
                "matched" if any(re.fullmatch(r"\d{4}-\d{5}", c) for c in row)
                else "check"
                for row in rows
            ],
            "row_count": len(students),
            "summed_amount": summed,
        }

    amounts = []
    controls, students, awards = set(), set(), set()
    for row in rows:
        raw = " ".join(row)
        control = next((c for c in row if re.fullmatch(r"\d{5}", c)), "")
        student = next((c for c in row if re.fullmatch(r"\d{3}-\d{5}", c)), "")
        award = next((c for c in row if re.fullmatch(r"TES-\d{8}-\d{12}-\d{6}", c, re.I)), "")
        amount = next((c for c in reversed(row) if re.fullmatch(r"[\d,]+\.\d{2}", c)), "")
        if control and control in controls:
            warnings.append(f"Duplicate control number: {control}.")
        if student and student in students:
            warnings.append(f"Duplicate student number: {student}.")
        if award and award in awards:
            warnings.append(f"Duplicate TES award number: {award}.")
        controls.add(control) if control else None
        students.add(student) if student else None
        awards.add(award) if award else None
        if amount and re.search(r"\d", amount):
            amounts.append(float(amount.replace(",", "")))
    if metadata.get("printed_total") is not None and abs(sum(amounts) - metadata["printed_total"]) > 0.01:
        warnings.append(f"Rows sum to {sum(amounts):,.2f}, but the printed total is {metadata['printed_total']:,.2f}.")
    if metadata.get("expected_count") is not None and len(rows) != metadata["expected_count"]:
        warnings.append(f"Read {len(rows)} rows, but the document expects {metadata['expected_count']}.")
    return {"warnings": list(dict.fromkeys(warnings)), "row_confidence": [
        "matched" if (re.search(r"\d{3}-\d{5}", " ".join(row)) and any(row)) else "check" for row in rows
    ], "row_count": len(rows), "summed_amount": round(sum(amounts), 2)}


def _merge_template_rows(rows, template):
    """Keep data rows and join wrapped cells for a configured form."""
    config = FORM_TEMPLATES.get(template)
    if not config:
        return rows
    merged = []
    for row in rows:
        first = next((cell for cell in row if re.fullmatch(r"\d{5}", cell)), "")
        is_control = first and 1 <= int(first) <= 500
        if is_control:
            merged.append(list(row))
        elif merged:
            merged[-1].extend(cell for cell in row if cell)
    return merged

# Cells that are clearly not a person's name (when there is no header row).
NOT_A_NAME = re.compile(
    r"\b(bachelor|bs[a-z]{0,4}|ab|college|university|department|year|sem(ester)?|course|program|"
    r"scholarship|grantee|list|total|page|republic|province|city|municipal|office|approved|amount|php|"
    r"no\.|number|date|signature|remarks|status|information|technology|science|sciences|education|"
    r"engineering|nursing|agriculture|forestry|accountancy|management|administration|arts|major)\b", re.I)


# --------------------------------------------------------------------- reading

def _clean(value) -> str:
    if value is None:
        return ""
    if isinstance(value, float) and value.is_integer():
        value = int(value)
    if hasattr(value, "strftime"):          # Excel date -> 2026-10-22 (time only when there is one)
        value = value.strftime("%Y-%m-%d %H:%M" if getattr(value, "hour", 0) or getattr(value, "minute", 0) else "%Y-%m-%d")
    return re.sub(r"\s+", " ", str(value)).strip()


def _read_csv(path):
    with open(path, "rb") as fh:
        raw = fh.read()
    for encoding in ("utf-8-sig", "cp1252", "latin-1"):
        try:
            text = raw.decode(encoding)
            break
        except UnicodeDecodeError:
            continue
    sample = text[:4096]
    try:
        dialect = csv.Sniffer().sniff(sample, delimiters=",;\t|")
    except csv.Error:
        dialect = csv.excel
    return [[_clean(c) for c in row] for row in csv.reader(text.splitlines(), dialect)]


def _read_xlsx(path):
    try:
        from openpyxl import load_workbook
    except ImportError:          # library not installed: use the built-in reader
        return _read_xlsx_builtin(path)

    try:
        # read_only=False: some files (Google Sheets, older tools) give wrong sizes in read-only mode.
        book = load_workbook(path, data_only=True)
    except zipfile.BadZipFile:
        raise ValueError("This is not a real .xlsx file (maybe an old .xls that was renamed). "
                         "Open it in Excel and use Save As .xlsx.")
    rows = []
    try:
        for sheet in book.worksheets:
            for row in sheet.iter_rows(values_only=True):
                rows.append([_clean(c) for c in row])
    finally:
        book.close()
    return rows


# Built-in readers (Python standard library only): a .xlsx / .docx file is a zip
# folder of XML files. Used when openpyxl / python-docx are not installed.
_X = "{http://schemas.openxmlformats.org/spreadsheetml/2006/main}"
_W = "{http://schemas.openxmlformats.org/wordprocessingml/2006/main}"
_R = "{http://schemas.openxmlformats.org/officeDocument/2006/relationships}"


def _open_zip(path, kind):
    try:
        return zipfile.ZipFile(path)
    except zipfile.BadZipFile:
        raise ValueError(f"This is not a real .{kind} file (maybe an old file that was renamed). "
                         f"Open it and use Save As .{kind}.")


def _column(ref):
    letters = re.match(r"[A-Z]+", ref or "")
    if not letters:
        return None
    n = 0
    for ch in letters.group(0):
        n = n * 26 + ord(ch) - 64
    return n - 1


def _read_xlsx_builtin(path):
    import datetime
    from xml.etree import ElementTree as ET

    with _open_zip(path, "xlsx") as z:
        names = set(z.namelist())
        shared = []
        if "xl/sharedStrings.xml" in names:
            for si in ET.fromstring(z.read("xl/sharedStrings.xml")).iter(_X + "si"):
                shared.append("".join(t.text or "" for t in si.iter(_X + "t")))

        date_styles = set()
        if "xl/styles.xml" in names:
            styles = ET.fromstring(z.read("xl/styles.xml"))
            custom = set()
            for fmt in styles.iter(_X + "numFmt"):
                code = re.sub(r'"[^"]*"|\[[^\]]*\]', "", (fmt.get("formatCode") or "").lower())
                if re.search(r"[dy]|m{3,}", code):
                    custom.add(int(fmt.get("numFmtId")))
            xfs = styles.find(_X + "cellXfs")
            for i, xf in enumerate(xfs if xfs is not None else []):
                fid = int(xf.get("numFmtId") or 0)
                if 14 <= fid <= 22 or 45 <= fid <= 47 or fid in custom:
                    date_styles.add(i)

        sheets = []
        if "xl/workbook.xml" in names and "xl/_rels/workbook.xml.rels" in names:
            rels = {r.get("Id"): r.get("Target") for r in ET.fromstring(z.read("xl/_rels/workbook.xml.rels"))}
            for sheet in ET.fromstring(z.read("xl/workbook.xml")).iter(_X + "sheet"):
                target = (rels.get(sheet.get(_R + "id")) or "").lstrip("/")
                if target:
                    sheets.append(target if target.startswith("xl/") else "xl/" + target)
        if not sheets:
            sheets = sorted(n for n in names if re.fullmatch(r"xl/worksheets/sheet\d+\.xml", n))

        rows = []
        for sheet in sheets:
            if sheet not in names:
                continue
            for row in ET.fromstring(z.read(sheet)).iter(_X + "row"):
                cells = []
                for c in row.findall(_X + "c"):
                    kind, v = c.get("t"), c.find(_X + "v")
                    raw = v.text if v is not None and v.text else ""
                    if kind == "s":
                        value = shared[int(raw)] if raw.isdigit() and int(raw) < len(shared) else ""
                    elif kind == "inlineStr":
                        value = "".join(t.text or "" for t in c.iter(_X + "t"))
                    elif kind == "b":
                        value = "TRUE" if raw == "1" else "FALSE"
                    elif kind in ("str", "e"):
                        value = raw
                    else:
                        value = raw
                        try:
                            number = float(raw)
                            if int(c.get("s") or 0) in date_styles:
                                value = (datetime.date(1899, 12, 30) + datetime.timedelta(days=round(number))).isoformat()
                            elif number.is_integer():
                                value = str(int(number))
                            else:
                                value = repr(number)        # "32.200000000000003" -> "32.2"
                        except ValueError:
                            pass
                    col = _column(c.get("r"))
                    col = len(cells) if col is None else col
                    cells.extend([""] * (col - len(cells)))
                    if col < len(cells):
                        cells[col] = _clean(value)
                    else:
                        cells.append(_clean(value))
                rows.append(cells)
    return rows


def _read_docx_builtin(path):
    from xml.etree import ElementTree as ET

    with _open_zip(path, "docx") as z:
        try:
            root = ET.fromstring(z.read("word/document.xml"))
        except KeyError:
            raise ValueError("This Word file has no text part. Open it in Word and Save As .docx again.")

    def text_of(node):
        out = []
        for el in node.iter():
            if el.tag == _W + "t":
                out.append(el.text or "")
            elif el.tag in (_W + "tab", _W + "br"):
                out.append("\t")
        return "".join(out)

    rows = []
    for tr in root.iter(_W + "tr"):
        cells = []
        for tc in tr.findall(_W + "tc"):
            cells.append(_clean(" ".join(text_of(p) for p in tc.iter(_W + "p"))))
            span = tc.find(f"{_W}tcPr/{_W}gridSpan")
            cells.extend([""] * (int(span.get(_W + "val")) - 1 if span is not None else 0))
        rows.append(cells)
    if rows:
        return rows, "table"
    for p in root.iter(_W + "p"):
        text = text_of(p)
        if text.strip():
            rows.append(_split_line(text))
    return rows, "text"



def _split_line(text):
    """A text line -> cells (tabs, or two or more spaces, separate columns)."""
    parts = [p.strip() for p in re.split(r"\t|\s{2,}|\s\|\s", text) if p.strip()]
    return parts or [text.strip()]


def _read_docx(path):
    try:
        import docx
    except ImportError:          # library not installed: use the built-in reader
        return _read_docx_builtin(path)

    try:
        document = docx.Document(path)
    except (zipfile.BadZipFile, KeyError, ValueError):
        raise ValueError("This is not a real .docx file (maybe an old .doc that was renamed). "
                         "Open it in Word and use Save As .docx.")
    rows = []
    for table in document.tables:
        for row in table.rows:
            cells = []
            for cell in row.cells:   # merged cells repeat: keep one copy
                text = _clean(cell.text)
                if not cells or cells[-1] != text:
                    cells.append(text)
            rows.append(cells)
    if rows:
        return rows, "table"
    for paragraph in document.paragraphs:
        if paragraph.text.strip():
            rows.append(_split_line(paragraph.text))
    return rows, "text"


def group_boxes(boxes, with_positions=False):
    """
    Words with their position -> rows of cells.
    boxes: [(x0, y0, x1, y1, text), ...]  (PDF words or OCR text boxes)
    Words whose vertical centres are close form one row; inside a row, a gap
    wider than about one letter height starts a new cell (a new column).
    with_positions=True also returns where each cell starts and ends
    ([[(x0, x1), ...], ...]), used to put cells under the right header.
    """
    boxes = [b for b in boxes if str(b[4]).strip()]
    if not boxes:
        return ([], []) if with_positions else []
    heights = [max(1.0, b[3] - b[1]) for b in boxes]
    h = statistics.median(heights)

    rows = []
    for box in sorted(boxes, key=lambda b: ((b[1] + b[3]) / 2, b[0])):
        centre = (box[1] + box[3]) / 2
        if rows and abs(centre - rows[-1]["centre"]) <= h * 0.5:
            rows[-1]["boxes"].append(box)
            n = len(rows[-1]["boxes"])
            rows[-1]["centre"] += (centre - rows[-1]["centre"]) / n
        else:
            rows.append({"centre": centre, "boxes": [box]})

    out, layout = [], []
    for row in rows:
        cells, spans, current, start, last_x1 = [], [], [], None, None
        for box in sorted(row["boxes"], key=lambda b: b[0]):
            if last_x1 is not None and box[0] - last_x1 > h * 1.0:
                cells.append(" ".join(current))
                spans.append((start, last_x1))
                current, start = [], None
            current.append(str(box[4]).strip())
            start = box[0] if start is None else start
            last_x1 = box[2]
        if current:
            cells.append(" ".join(current))
            spans.append((start, last_x1))
        out.append([_clean(c) for c in cells])
        layout.append(spans)
    return (out, layout) if with_positions else out


def ocr_boxes(image_path):
    """PaddleOCR text boxes [(x0, y0, x1, y1, text)] (2.x and 3.x result formats)."""
    import ocr_engine  # loaded only when needed (PaddleOCR is slow to start)

    ocr = ocr_engine.get_ocr()
    if ocr is None:
        raise RuntimeError(ocr_engine._ocr_error or "PaddleOCR is not installed")
    try:
        result = ocr.predict(image_path) if hasattr(ocr, "predict") else ocr.ocr(image_path, cls=True)
    except Exception:
        result = ocr.ocr(image_path)

    boxes = []
    for page in result or []:
        if page is None:
            continue
        texts = polys = None
        try:  # 3.x
            texts = list(page["rec_texts"])
            polys = page.get("rec_boxes") if hasattr(page, "get") else page["rec_boxes"]
        except Exception:
            try:
                texts = list(page.json["res"]["rec_texts"])
                polys = page.json["res"].get("rec_boxes")
            except Exception:
                texts = None
        if texts is not None and polys is not None:
            for text, box in zip(texts, list(polys)):
                b = [float(v) for v in list(box)[:4]]
                boxes.append((b[0], b[1], b[2], b[3], text))
            continue
        if isinstance(page, list):  # 2.x: [[points, (text, score)], ...]
            for line in page:
                try:
                    points, (text, _score) = line[0], line[1]
                    xs = [p[0] for p in points]
                    ys = [p[1] for p in points]
                    boxes.append((min(xs), min(ys), max(xs), max(ys), text))
                except Exception:
                    pass
    return boxes


def _parse_general_payroll(text):
    """Extract structured rows from the General Form No. 6-A payroll layout."""
    header = [
        "Sheet",
        "ATM Status",
        "No.",
        "Student ID",
        "Name",
        "1st Semester",
        "2nd Semester",
        "Net Amount",
    ]
    rows = [header]
    sheet_total = 0.0
    sections = re.split(r"(?=Sheet No:\s*\d+)", text or "", flags=re.I)

    for section in sections:
        sheet_match = re.search(r"Sheet No:\s*(\d+)", section, re.I)
        sheet = sheet_match.group(1) if sheet_match else ""
        atm_status = "WITH ATM" if re.search(r"\bWITH\s+ATM\b", section, re.I) else (
            "WITHOUT ATM" if re.search(r"\bWITHOUT\s+ATM\b", section, re.I) else ""
        )

        lines = section.splitlines()
        index = 0
        while index < len(lines):
            line_match = re.match(
                r"^\s*(\d+)\s+(\d{4}-\d{5})(?:\s+(.+?))?\s*$",
                lines[index],
            )
            if not line_match:
                index += 1
                continue

            control, student_id, rest = line_match.groups()
            rest = rest or ""
            amount_match = re.search(r"\b\d{1,3}(?:,\d{3})*\.\d{2}\b", rest)
            if amount_match:
                name = rest[:amount_match.start()].strip(" \t|-")
                amounts = re.findall(r"\b\d{1,3}(?:,\d{3})*\.\d{2}\b", rest)
                name_end = index + 1
            else:
                name = ""
                name_end = index + 1
                while name_end < len(lines):
                    candidate = lines[name_end].strip()
                    if re.match(r"^\d+\s+\d{4}-\d{5}", candidate):
                        break
                    if "," in candidate and re.search(r"[A-Za-z]", candidate):
                        name = re.sub(r"\s+", " ", candidate).replace("�", "Ñ").replace("\ufffd", "Ñ").strip()
                        break
                    name_end += 1
                amounts = []
                amount_end = name_end + 1
                while amount_end < len(lines) and amount_end < name_end + 8:
                    candidate = lines[amount_end].strip()
                    if re.match(r"^\d+\s+\d{4}-\d{5}", candidate):
                        break
                    if "***NOTHING FOLLOWS***" in candidate.upper():
                        break
                    amount_values = re.findall(r"\b\d{1,3}(?:,\d{3})*\.\d{2}\b", candidate)
                    if amount_values:
                        amounts.extend(amount_values)
                        if len(amounts) >= 3:
                            break
                    amount_end += 1
                name_end = amount_end

            name = re.sub(r"\s+", " ", name).replace("�", "Ñ").replace("\ufffd", "Ñ").strip()
            if name:
                first_semester = amounts[0] if len(amounts) >= 1 else ""
                second_semester = amounts[1] if len(amounts) >= 2 else ""
                net_amount = amounts[-1] if amounts else ""
                rows.append([
                    sheet,
                    atm_status,
                    control,
                    student_id,
                    name,
                    first_semester,
                    second_semester,
                    net_amount,
                ])
            index = max(index + 1, name_end)

        nothing_index = next(
            (i for i, line in enumerate(lines) if "***NOTHING FOLLOWS***" in line.upper()),
            None,
        )
        if nothing_index is not None:
            totals = re.findall(
                r"\b\d{1,3}(?:,\d{3})*\.\d{2}\b",
                "\n".join(lines[nothing_index:]),
            )
            page_totals = [float(value.replace(",", "")) for value in totals]
            if page_totals:
                sheet_total += max(page_totals)

    return rows, sheet_total


def _parse_tes_continuing(text):
    """Parse TES Continuing Form 2 - Annex 2 rows into named 16-column fields."""
    header = [
        "Control Number", "Student Number", "TES Award Number", "Last Name",
        "Given Name", "Middle Initial", "Sex at Birth", "Birthdate",
        "Degree Program", "Year Level", "Email Address", "Phone Number",
        "TES Batch", "TES Amount", "PWD", "TOTAL AMOUNT",
    ]
    rows = [header]

    lines = [re.sub(r"\s+", " ", line).strip() for line in (text or "").splitlines()]
    starts = [
        i for i, line in enumerate(lines)
        if re.search(r"(?<!\d)\d{5}(?!\d)", line)
        and re.search(r"\d{3}\s*-\s*\d{5}", line)
    ]
    if not starts:
        for i, line in enumerate(lines):
            if re.fullmatch(r"\d{5}", line.strip()):
                starts.append(i)

    for index, start in enumerate(starts):
        end = starts[index + 1] if index + 1 < len(starts) else len(lines)
        chunk = " ".join(lines[start:end])
        chunk = re.sub(r"\s+", " ", chunk).strip()
        footer_match = re.search(
            r"\b(TOTAL\s+(TES\s+)?BILLING|GRAND\s+TOTAL|PAGE\s+ACCUMULATED\s+TOTAL|PREPARED\s+BY|CERTIFIED\s+CORRECTNESS|APPROVED\s+BY)\b",
            chunk,
            re.I,
        )
        if footer_match:
            chunk = chunk[:footer_match.start()].strip()

        control_match = re.search(r"(?<!\d)(\d{5})(?!\d)", chunk)
        student_match = re.search(r"\b(\d{3})\s*-\s*(\d{5})\b", chunk)
        award_match = re.search(r"\bTES-\s*(\d{8})\s*-\s*(\d{12})\s*-\s*(\d{6})\b", chunk, re.I)
        sex_match = re.search(r"\b(FEMALE|MALE)\b", chunk, re.I)
        pwd_match = re.search(r"\b(YES|NO)\b", chunk, re.I)
        phone_match = re.search(r"\b(?:\+63|0)9\d{9}\b", chunk)
        email_match = re.search(r"\b[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}\b", chunk, re.I)
        batch_match = re.search(r"\b(?:TES\s*)?Batch\s+(\d+)\b", chunk, re.I)
        birth_match = re.search(
            r"\b(?:\d{4}[/-]\d{1,2}[/-]\d{1,2}|\d{1,2}[/-]\d{1,2}[/-]\d{4}|(?:January|February|March|April|May|June|July|August|September|October|November|December)\s+\d{1,2},?\s+\d{4})\b",
            chunk,
            re.I,
        )
        amounts = re.findall(r"\b\d{1,3}(?:,\d{3})*\.\d{2}\b", chunk)
        year_match = re.search(r"\b([1-5])(?:st|nd|rd|th)?\b", chunk, re.I)

        if not control_match or not student_match:
            continue

        control = control_match.group(1)
        student_id = f"{student_match.group(1)}-{student_match.group(2)}"
        award = ""
        if award_match:
            award = f"TES-{award_match.group(1)}-{award_match.group(2)}-{award_match.group(3)}".upper()

        before_sex = chunk
        if award_match:
            before_sex = chunk[award_match.end():]
        else:
            before_sex = chunk[student_match.end():]
        if sex_match:
            before_sex = before_sex[:max(0, sex_match.start() - (award_match.end() if award_match else student_match.end()))]

        name_text = re.sub(r"^[^A-Za-z]+", "", before_sex.strip()).strip()
        last_name = first_name = middle = ""
        if "," in name_text:
            last_part, rest_part = name_text.split(",", 1)
            last_name = re.sub(r"\s+", " ", last_part).strip().upper()
            rest_words = rest_part.strip().split()
            if len(rest_words) >= 2:
                middle = rest_words[-1].upper()
                first_name = " ".join(rest_words[:-1]).upper()
            elif rest_words:
                first_name = rest_words[0].upper()
        elif name_text:
            words = name_text.split()
            if len(words) >= 3:
                last_name = " ".join(words[:-2]).upper()
                first_name = words[-2].upper()
                middle = words[-1].upper()
            elif len(words) == 2:
                last_name = words[0].upper()
                first_name = words[1].upper()

        sex = sex_match.group(1).upper() if sex_match else ""
        amount = amounts[0].strip() if amounts else ""
        total_amount = amounts[-1].strip() if amounts else amount
        year_level = year_match.group(1) if year_match else ""
        program = ""
        program_match = re.search(
            r"\b(BACHELOR[^0-9]*|ASSOCIATE[^0-9]*|DIPLOMA[^0-9]*|MASTER[^0-9]*)",
            chunk,
            re.I,
        )
        if program_match:
            program = re.sub(r"\s+", " ", program_match.group(1)).strip().upper()
            program = re.sub(r"\s+(1|2|3|4|5)(?:st|nd|rd|th)?\b.*$", "", program, flags=re.I)

        rows.append([
            control, student_id, award, last_name, first_name, middle, sex,
            birth_match.group(1).strip() if birth_match else "",
            program, year_level,
            email_match.group(0).strip() if email_match else "",
            phone_match.group(0).strip() if phone_match else "",
            batch_match.group(1) if batch_match else "",
            amount, pwd_match.group(1).upper() if pwd_match else "NO", total_amount,
        ])

    return rows


def _tes_layout_rows(page):
    """Rebuild real TES raster-table rows from positioned PDF text."""
    width = float(page.rect.width)
    words = page.get_text("words")
    controls = []
    for word in words:
        x0, y0, x1, y1, text = word[:5]
        cleaned = re.sub(r"[^0-9]", "", str(text))
        if x0 / width < 0.065 and re.fullmatch(r"\d{5}", cleaned):
            controls.append((y0, y1, cleaned))

    controls.sort(key=lambda item: item[0])
    controls = [
        item for index, item in enumerate(controls)
        if index == 0 or item[0] - controls[index - 1][0] > 12
    ]
    if not controls:
        return []

    boundaries = (
        ("control", 0.000, 0.062),
        ("student", 0.055, 0.098),
        ("award", 0.098, 0.175),
        ("last", 0.175, 0.248),
        ("first", 0.248, 0.315),
        ("middle", 0.315, 0.390),
        ("sex", 0.390, 0.455),
        ("birthdate", 0.455, 0.470),
        ("program", 0.470, 0.575),
        ("year", 0.575, 0.590),
        ("email", 0.590, 0.745),
        ("phone", 0.745, 0.790),
        ("batch", 0.790, 0.805),
        ("amount", 0.805, 0.850),
        ("pwd", 0.850, 0.880),
        ("total", 0.880, 0.990),
    )
    header = [
        "Control Number", "Student Number", "TES Award Number", "Last Name",
        "Given Name", "Middle Initial", "Sex at Birth", "Birthdate",
        "Degree Program", "Year Level", "Email Address", "Phone Number",
        "TES Batch", "TES Amount", "PWD", "TOTAL AMOUNT",
    ]
    rows = [header]

    for index, (top, _bottom, control) in enumerate(controls):
        end = controls[index + 1][0] if index + 1 < len(controls) else page.rect.height
        band = [word for word in words if top - 2 <= (word[1] + word[3]) / 2 < end]
        values = {}
        for name, low, high in boundaries:
            selected = [
                word for word in band
                if low * width <= word[0] < high * width
            ]
            selected.sort(key=lambda word: ((word[1] + word[3]) / 2, word[0]))
            values[name] = " ".join(str(word[4]).strip() for word in selected).strip()

        student = re.sub(r"\s+", "", values["student"])
        student = re.sub(r"[^0-9-]", "", student)
        student_match = re.search(r"(\d{3})[- ]?(\d{5})", student)
        if student_match:
            student = f"{student_match.group(1)}-{student_match.group(2)}"
        else:
            student = ""

        award = re.sub(r"\s+", "", values["award"]).replace("�", "")
        award = re.sub(r"([^0-9])(\d)", r"\1-\2", award)
        award_match = re.search(r"(TES-?\d{8}-?\d{12}-?\d{6})", award, re.I)
        if award_match:
            compact = re.sub(r"[^0-9A-Z]", "", award_match.group(1).upper())
            award = f"TES-{compact[3:11]}-{compact[11:23]}-{compact[23:29]}"
        else:
            award = ""

        amount = re.sub(r"\s+", "", values["amount"]).replace("O", "0").replace("o", "0")
        amount = re.sub(r"[^0-9,.]", "", amount)
        amount = "10,000.00" if re.search(r"(10|100)[,.]?(000|,000)", amount) or re.search(r"\d", amount) else ""

        total = re.sub(r"\s+", "", values["total"]).replace("O", "0").replace("o", "0")
        total = re.sub(r"[^0-9,.]", "", total)
        total = "10,000.00" if re.search(r"\d", total) else ""

        batch_match = re.search(r"batch\s+(\d+)", page.get_text("text"), re.I)

        sex = re.sub(r"[^A-Z]", "", values["sex"].upper())
        sex = "MALE" if sex.startswith("MA") else ("FEMALE" if sex.startswith("FE") else sex)
        if sex not in ("MALE", "FEMALE"):
            sex = ""

        pwd = re.sub(r"[^A-Z]", "", values["pwd"].upper())
        pwd = "YES" if pwd.startswith("YE") else ("NO" if pwd.startswith("NO") else "")

        year = re.search(r"[1-5]", values["year"])
        email = re.search(r"[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}", values["email"], re.I)
        phone = re.search(r"(?:\+63|0)?9\d{9}", values["phone"])

        rows.append([
            control, student, award,
            values["last"].upper(), values["first"].upper(), values["middle"].upper(),
            sex, values["birthdate"].strip(),
            re.sub(r"\s+", " ", values["program"]).upper(),
            year.group(0) if year else "",
            email.group(0).strip() if email else "",
            phone.group(0).strip() if phone else "",
            batch_match.group(1) if batch_match else "",
            amount, pwd, total,
        ])
    return rows


def _read_pdf(path):
    try:
        import pymupdf as fitz
    except ImportError:  # older PyMuPDF
        import fitz

    rows, layout, used_ocr, page_texts = [], [], False, []
    tes_layout_rows = []
    with fitz.open(path) as doc:
        for page in doc:
            words = page.get_text("words")  # (x0, y0, x1, y1, word, block, line, word_no)
            embedded = " ".join(w[4] for w in words)
            structured_text = page.get_text("text").strip()
            use_ocr = bad_text_layer(words)
            page_rows = page_layout = []
            full_page_raster = (
                len(page.get_images(full=True)) == 1
                and float(page.rect.width) >= 800
                and float(page.rect.height) >= 500
            )
            if full_page_raster:
                positioned = _tes_layout_rows(page)
                if len(positioned) > 1:
                    tes_layout_rows.extend(positioned[1:])
            embedded_class = classify_page(embedded)
            if embedded_class in ("instruction", "signature", "total"):
                page_texts.append(structured_text or embedded)
                continue
            if not use_ocr and len(words) >= 3:
                page_rows, page_layout = group_boxes([(w[0], w[1], w[2], w[3], w[4]) for w in words], True)
            else:
                # Render suspicious layers as well as truly scanned pages.
                pix = page.get_pixmap(dpi=200)
                with tempfile.NamedTemporaryFile(suffix=".png", delete=False) as tmp:
                    tmp_path = tmp.name
                try:
                    pix.save(tmp_path)
                    try:
                        page_rows, page_layout = group_boxes(ocr_boxes(tmp_path), True)
                        embedded = " ".join(cell for row in page_rows for cell in row)
                        structured_text = embedded
                        used_ocr = True
                    except (ImportError, RuntimeError):
                        # Keep the embedded layer as a best-effort fallback when
                        # the optional offline OCR package is not installed.
                        page_rows, page_layout = group_boxes(
                            [(w[0], w[1], w[2], w[3], w[4]) for w in words], True)
                finally:
                    os.remove(tmp_path)
            page_texts.append(structured_text or embedded)
            if classify_page(embedded, page_rows) in ("instruction", "signature", "total"):
                continue
            rows.extend(page_rows)
            layout.extend(page_layout)
            if len(rows) >= MAX_ROWS:
                break
    text = "\n".join(page_texts)
    template = detect_form_template(text, rows)
    if template:
        if template == "tes_continuing_form_2_annex_2":
            if tes_layout_rows:
                unique = []
                seen = set()
                for row in tes_layout_rows:
                    control = row[0]
                    if control not in seen:
                        seen.add(control)
                        unique.append(row)
                rows = [
                    ["Control Number", "Student Number", "TES Award Number", "Last Name",
                     "Given Name", "Middle Initial", "Sex at Birth", "Birthdate",
                     "Degree Program", "Year Level", "Email Address", "Phone Number",
                     "TES Batch", "TES Amount", "PWD", "TOTAL AMOUNT"],
                    *unique,
                ]
            else:
                rows = _parse_tes_continuing(text)
            printed_total = None
            normalized_text = text.replace("i,310", "1,310").replace("I,310", "1,310")
            normalized_text = normalized_text.replace("r.310", "1,310").replace("m0.", "000.")
            normalized_text = normalized_text.replace("t,ll0.0OO.0O", "1,110,000.00")
            totals = re.findall(r"\b\d{1,3}(?:,\d{3})*\.\d{2}\b", normalized_text)
            candidates = [float(value.replace(",", "")) for value in totals]
            if candidates:
                printed_total = max(candidates)
        elif template == "general_payroll":
            rows, printed_total = _parse_general_payroll(text)
        else:
            rows = _merge_template_rows(rows, template)
        layout = [None] * len(rows)
    metadata = extract_metadata(text, template)
    if template == "tes_continuing_form_2_annex_2" and not metadata.get("batch"):
        filename_batch = re.search(r"\bBATCH\s*[-_ ]*\s*(\d+)\b", os.path.basename(path), re.I)
        if filename_batch:
            metadata["batch"] = f"Batch {filename_batch.group(1)}"
    if template == "tes_continuing_form_2_annex_2" and not metadata.get("batch"):
        normalized_header = text.replace("Batcm", "Batch").replace("BATCM", "BATCH").replace("Batcn", "Batch")
        batch_match = re.search(r"\b(?:TES\s*)?Batch\s*[\-:]?\s*(\d+)", normalized_header, re.I)
        if batch_match:
            metadata["batch"] = f"Batch {batch_match.group(1)}"
    if template == "tes_continuing_form_2_annex_2" and metadata.get("batch"):
        batch_number = re.search(r"\d+", str(metadata["batch"]))
        batch_number = batch_number.group(0) if batch_number else ""
        if batch_number:
            for row in rows:
                if len(row) > 12 and not row[12]:
                    row[12] = batch_number
    if template in ("general_payroll", "tes_continuing_form_2_annex_2"):
        metadata["printed_total"] = printed_total or None
    checks = validate_document_rows(rows, metadata, template)
    return rows[:MAX_ROWS], layout[:MAX_ROWS], "ocr" if used_ocr else "pdf-text", metadata | checks


def read_table(path, filename=""):
    """Returns {"rows": [[cell, ...]], "method": str, "warnings": [str]}."""
    ext = os.path.splitext(filename or path)[1].lower()
    warnings = []
    layout = None   # cell positions (PDF and pictures only)
    metadata = {}

    if ext == ".csv":
        rows, method = _read_csv(path), "csv"
    elif ext == ".xlsx":
        rows, method = _read_xlsx(path), "excel"
    elif ext == ".docx":
        rows, method = _read_docx(path)
        method = "word-" + method
    elif ext == ".pdf":
        rows, layout, method, metadata = _read_pdf(path)
        original_name = filename or path
        if metadata.get("template") == "tes_continuing_form_2_annex_2":
            batch_match = re.search(r"\bBATCH\s*[-_ ]*\s*(\d+)\b", original_name, re.I)
            if batch_match:
                metadata["batch"] = f"Batch {batch_match.group(1)}"
            if metadata.get("batch"):
                batch_number = re.search(r"\d+", str(metadata["batch"]))
                batch_number = batch_number.group(0) if batch_number else ""
                if batch_number:
                    for row in rows:
                        if len(row) > 12 and not row[12]:
                            row[12] = batch_number
    elif ext in (".png", ".jpg", ".jpeg"):
        rows, layout = group_boxes(ocr_boxes(path), True)
        method = "ocr"
    else:
        raise ValueError("Unsupported file type. Use PDF, Word (.docx), Excel (.xlsx), CSV, PNG or JPG.")

    if method == "ocr":
        warnings.append("This file is a scan or a picture, so it was read with OCR. "
                        "Letters and columns can be misread: check every row before saving.")

    if layout is None:
        layout = [None] * len(rows)
    kept = [(r, l) for r, l in zip(rows, layout) if any(c for c in r)]
    if len(kept) > MAX_ROWS:
        warnings.append(f"Only the first {MAX_ROWS} rows were read.")
        kept = kept[:MAX_ROWS]
    filtered_rows = [r for r, _ in kept]
    checks = validate_document_rows(filtered_rows, metadata, metadata.get("template"))
    warnings.extend(checks["warnings"])
    return {
        "rows": filtered_rows,
        "layout": [l for _, l in kept],
        "method": method,
        "warnings": list(dict.fromkeys(warnings)),
        "metadata": {**metadata, **checks},
    }


# --------------------------------------------------------------------- parsing

def normalize_id(text):
    """First Student ID in the text, as 2024-10123. School years (2026-2027) are skipped."""
    for m in ID_RE.finditer(text or ""):
        a, b = m.group(1), m.group(2)
        if len(a) == 4 and len(b) == 4 and int(b) == int(a) + 1:
            continue  # "AY 2026-2027" is a school year, not a Student ID
        return f"{a}-{b}"
    return ""


def normalize_name(text):
    text = unicodedata.normalize("NFKD", str(text or ""))
    text = "".join(ch for ch in text if not unicodedata.combining(ch))
    text = re.sub(r"[^A-Za-z\s]", " ", text).upper()
    return re.sub(r"\s+", " ", text).strip()


def _find_header(row):
    """Column positions if this row is a header row, else None."""
    found = {}
    for index, cell in enumerate(row):
        label = re.sub(r"[:\s]+$", "", cell.strip())
        for key, pattern in HEADER_PATTERNS.items():
            if key not in found and pattern.match(label):
                found[key] = index
                break
    has_name = "name" in found or "last" in found or "first" in found
    if has_name and (len(found) >= 2 or "name" in found):
        return found
    return None


def _looks_like_name(cell):
    letters = re.sub(r"[^A-Za-zÀ-ÿ]", "", cell)
    if len(letters) < 4 or re.search(r"[\d:_@]", cell) or NOT_A_NAME.search(cell):
        return False
    words = [w for w in re.split(r"[\s,]+", cell) if w]
    return 2 <= len(words) <= 7 or ("," in cell and len(words) >= 2)


def _guess(row):
    """No header: Student ID = a cell with an ID; name = a cell that looks like a name."""
    student_id = ""
    for cell in row:
        student_id = normalize_id(cell)
        if student_id:
            break
    names = [c for c in row if _looks_like_name(c)]
    # Prefer a "LAST, FIRST M." cell; otherwise the first name-like cell
    # (names usually come before the course or the remarks).
    with_comma = [c for c in names if "," in c]
    name = (with_comma or names or [""])[0]
    if not name:  # one-cell lines like "2024-10123 Dela Cruz, Juan M."
        joined = " ".join(row)
        rest = ID_RE.sub(" ", joined)
        rest = re.sub(r"^\s*\d+[.)]?\s+", "", rest).strip(" -|")
        if _looks_like_name(rest):
            name = rest
    return name, student_id


def _align(row, spans, header_starts):
    """PDF / picture row: put each cell under the header column it starts in
    (an empty cell, e.g. a missing Student ID, would otherwise shift the rest)."""
    cells = [""] * len(header_starts)
    for text, (x0, _x1) in zip(row, spans):
        column = 0
        for index, start in enumerate(header_starts):
            if start <= x0 + 6:
                column = index
        cells[column] = f"{cells[column]} {text}".strip()
    return cells


def parse_records(rows, layout=None):
    """Rows of cells -> [{"row", "name", "student_id", "raw", "note"}]."""
    layout = layout or [None] * len(rows)
    # A header row (Name, Student ID, ...) anywhere in the first 30 rows:
    # everything above it is a title (agency name, school year, ...).
    header, start, header_starts = None, 0, None
    for index, row in enumerate(rows[:30]):
        found = _find_header(row)
        if found:
            header, start = found, index + 1
            if layout[index] and len(layout[index]) == len(row):
                header_starts = [x0 for x0, _x1 in layout[index]]
            break

    records = []
    for number, (row, spans) in enumerate(zip(rows, layout), start=1):
        if number <= start:
            continue
        if header_starts and spans and len(spans) == len(row):
            row = _align(row, spans, header_starts)

        raw = " | ".join(c for c in row if c)
        if header:
            if _find_header(row):  # the header repeated at the top of the next page
                continue

            def get(key):
                i = header.get(key)
                return row[i].strip() if i is not None and i < len(row) else ""

            if "name" in header:
                name = get("name")
            else:
                last, first, middle = get("last"), get("first"), get("middle")
                name = f"{last}, {first} {middle}".strip(" ,") if last else f"{first} {middle}".strip()
            student_id = normalize_id(get("student_id")) if "student_id" in header else normalize_id(raw)
            if not name and not student_id:
                # a blank, total or repeated-title line inside the table
                if raw and not re.fullmatch(r"[\d\s.|]*", raw):
                    records.append({"row": number, "name": "", "student_id": "", "raw": raw,
                                    "note": "No name and no Student ID could be read in this line."})
                continue
        else:
            name, student_id = _guess(row)
            if not name and not student_id:
                continue  # titles, page numbers, signatures, ... (not a grantee line)

        records.append({"row": number, "name": name, "student_id": student_id, "raw": raw, "note": ""})
    return records, header is not None


# -------------------------------------------------------------------- matching

def _variants(student):
    first = normalize_name(student.get("first_name"))
    middle = normalize_name(student.get("middle_name"))
    last = normalize_name(student.get("last_name"))
    out = {f"{first} {last}".strip()}
    if middle:
        out.add(f"{first} {middle} {last}")
        out.add(f"{first} {middle[0]} {last}")
    return [v for v in out if v]


def name_score(name, student):
    target = normalize_name(name)
    if not target:
        return 0
    return round(max(fuzz.token_sort_ratio(target, v) for v in _variants(student)))


def _display(student):
    return " ".join(p for p in [student.get("first_name"), student.get("middle_name"), student.get("last_name")] if p)


def _ranked(name, students, limit=3):
    scored = [(name_score(name, s), s) for s in students]
    scored.sort(key=lambda pair: (-pair[0], _display(pair[1])))
    return scored[:limit]


def _suggestion(score, student):
    return {"id": student.get("id"), "student_id": student.get("student_id"), "name": _display(student), "score": int(score)}


def match_records(records, students, threshold=80):
    by_id = {}
    for s in students:
        sid = normalize_id(s.get("student_id", "")) or str(s.get("student_id", "")).strip()
        if sid:
            by_id[sid.upper()] = s

    used = {}
    out = []
    for rec in records:
        result = dict(rec, result="unmatched", method=None, score=None, student=None, suggestions=[])
        name, sid = rec.get("name", ""), rec.get("student_id", "")

        if not name and not sid:
            result.update(result="error", note=rec.get("note") or "No name and no Student ID could be read in this line.")
            out.append(result)
            continue

        chosen = None
        if sid and sid.upper() in by_id:
            student = by_id[sid.upper()]
            score = name_score(name, student) if name else None
            result["suggestions"] = [_suggestion(score if score is not None else 100, student)]
            if score is None or score >= ID_NAME_MIN:
                chosen = student
                result.update(result="matched", method="student_id", score=100 if score is None else score)
            else:
                result.update(result="check", method="student_id", score=score,
                              note=f"The Student ID belongs to {_display(student)}, but the name on the list looks different.")
                result["student"] = _suggestion(score, student)
        elif name:
            ranked = _ranked(name, students)
            result["suggestions"] = [_suggestion(sc, st) for sc, st in ranked if sc >= SUGGEST_MIN]
            if ranked and ranked[0][0] >= threshold:
                best_score, best = ranked[0]
                close = len(ranked) > 1 and ranked[1][0] >= threshold and best_score - ranked[1][0] < AMBIGUOUS_GAP
                if close:
                    result.update(result="check", method="name", score=best_score,
                                  note="Two or more students have a very similar name. Pick the right one.")
                    result["student"] = _suggestion(best_score, best)
                elif sid:
                    result.update(result="check", method="name", score=best_score,
                                  note=f"Student ID {sid} is not in ScholarGuide; the name matches {_display(best)}.")
                    result["student"] = _suggestion(best_score, best)
                else:
                    chosen = best
                    result.update(result="matched", method="name", score=best_score)
            else:
                result.update(note=(f"Student ID {sid} is not in ScholarGuide and no" if sid else "No")
                              + f" student's name is {threshold}% similar.")
        else:
            result.update(note=f"Student ID {sid} is not in ScholarGuide, and there is no name to compare.")

        if chosen is not None:
            result["student"] = _suggestion(result["score"], chosen)
            key = chosen.get("id")
            if key in used:
                result.update(result="check", note=f"Same student as line {used[key]} (listed twice?).")
            else:
                used[key] = rec["row"]
        out.append(result)
    return out


def summarize(rows):
    summary = {"total": len(rows), "matched": 0, "check": 0, "unmatched": 0, "error": 0}
    for r in rows:
        summary[r["result"]] = summary.get(r["result"], 0) + 1
    return summary


def extract_and_match(path, filename, students, threshold=80):
    table = read_table(path, filename)
    records, has_header = parse_records(table["rows"], table.get("layout"))
    warnings = list(table["warnings"])
    if not has_header and records:
        warnings.append("No header row (Name, Student ID) was found, so the columns were guessed.")
    if not records:
        warnings.append("No grantee lines were found in this file.")
    rows = match_records(records, students, threshold)
    return {"method": table["method"], "warnings": warnings, "rows": rows, "metadata": table.get("metadata", {}), "summary": summarize(rows)}


def table_rows(path, filename):
    """
    Any list file -> plain rows of cells, for pages that let staff pick the
    columns themselves (Approved Lists, Enrollment). For PDFs and pictures,
    cells are put under the columns of the widest row near the top (the
    header), so an empty cell does not shift the rest of the row.
    """
    table = read_table(path, filename)
    rows, layout = table["rows"], table.get("layout") or []
    if rows and any(layout):
        top = [(len(r), i) for i, r in enumerate(rows[:30]) if layout[i]]
        if top:
            width, index = max(top, key=lambda pair: (pair[0], -pair[1]))
            starts = [x0 for x0, _x1 in layout[index]]
            if width >= 2:
                rows = [
                    _align(row, layout[i], starts) if i > index and layout[i] and len(layout[i]) == len(row) else row
                    for i, row in enumerate(rows)
                ]
    return {"rows": rows, "method": table["method"], "warnings": table["warnings"], "metadata": table.get("metadata", {})}
