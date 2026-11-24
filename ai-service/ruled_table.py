"""
Ruled-table reader for scanned government forms (ASSISTIVE ONLY).

Why this exists
---------------
CHED forms such as the TES Continuing "Consolidated Grantees Details" (the TES billing file) reach OAS as
SCANS. The scanner often adds its own hidden text layer, and that text is full of mistakes ("lES-" for
"TES-", "131-" for "231-"). One student also takes several lines (the student number, award number and
program wrap), and a row can continue on the next page. Reading the text line by line therefore mixes the
rows up.

What this module does instead
-----------------------------
1. Looks at the PAGE PICTURE, not the hidden text.
2. Finds the printed table lines (OpenCV), straightens a tilted page, and cuts the table into cells.
   One student = the space between two horizontal lines, so wrapped lines stay in one row.
3. Reads the words on the page with OCR (PaddleOCR when installed, otherwise Tesseract when installed;
   with neither, the PDF's own text is used and every cell is marked for checking) and puts every word into
   the cell it sits in.
4. Cleans the fields that have a strict format (control number, Student ID, award number, amounts, sex,
   year level, PWD, phone, e-mail). Every change is recorded. Names are NEVER corrected.
5. Joins a row that continues at the top of the next page, and checks the whole file: control numbers in
   order (a gap means a missing page), duplicates, count and sum against the printed total.

Nothing here approves anybody: the result is a preview that OAS staff check and edit before processing.
"""
import os
import re
import tempfile
import statistics

TES_COLUMNS = (
    "control_number", "student_id", "award_number", "last", "first", "middle", "sex", "birthdate",
    "program", "year_level", "email", "phone", "batch", "amount", "pwd", "total_amount",
)
TES_HEADER = (
    "Control Number", "Student Number", "TES Award Number", "Last Name", "Given Name", "Middle Initial",
    "Sex at Birth", "Birthdate", "Degree Program", "Year Level", "Email Address", "Phone Number",
    "TES Batch", "TES Amount", "PWD", "TOTAL AMOUNT",
)

# Layouts this reader knows. Add a new CHED form here (columns in order, header labels, strict fields).
RULED_TEMPLATES = {
    "tes_continuing_form_2_annex_2": {
        "title": "TES Continuing – Consolidated Grantees Details (TES billing)",
        "labels": ("consolidated continuing tes", "tes billing details", "tes continuing", "tes award number"),
        "columns": TES_COLUMNS,
        "header": TES_HEADER,
        "key": "control_number",          # a new student row starts where this column has a value
        # Where the 17 vertical lines of the table sit, from the left border (0) to the right border (1).
        # Measured on the CHED form; a page whose scan hides some lines is fitted to this pattern.
        "lines": (0.0, 0.0453, 0.0894, 0.1666, 0.2552, 0.3300, 0.4080, 0.4447, 0.4868, 0.6042, 0.6337,
                  0.7739, 0.8295, 0.8590, 0.9019, 0.9429, 1.0),
    },
}

LOW = 0.80   # cells below this confidence are highlighted for staff

DIGIT_FIX = str.maketrans({"O": "0", "o": "0", "Q": "0", "D": "0", "I": "1", "l": "1", "i": "1", "|": "1",
                           "!": "1", "L": "1", "Z": "2", "z": "2", "S": "5", "s": "5", "B": "8", "G": "6",
                           "b": "6", "g": "9", "q": "9", "T": "7", "A": "4", "€": "6"})


# ------------------------------------------------------------------ OCR engines

def _paddle_boxes(image_path):
    """PaddleOCR words as (x0, y0, x1, y1, text, confidence 0-1)."""
    import ocr_engine

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
        texts = polys = scores = None
        try:  # PaddleOCR 3.x
            texts = list(page["rec_texts"])
            polys = list(page["rec_boxes"])
            scores = list(page["rec_scores"])
        except Exception:
            try:
                res = page.json["res"]
                texts, polys, scores = list(res["rec_texts"]), list(res["rec_boxes"]), list(res["rec_scores"])
            except Exception:
                texts = None
        if texts is not None and polys is not None:
            for text, box, score in zip(texts, polys, scores or [1.0] * len(texts)):
                b = [float(v) for v in list(box)[:4]]
                boxes.append((b[0], b[1], b[2], b[3], str(text), float(score)))
            continue
        if isinstance(page, list):  # PaddleOCR 2.x: [[points, (text, score)], ...]
            for line in page:
                try:
                    points, (text, score) = line[0], line[1]
                    xs, ys = [p[0] for p in points], [p[1] for p in points]
                    boxes.append((min(xs), min(ys), max(xs), max(ys), str(text), float(score)))
                except Exception:
                    pass
    return boxes


def _tesseract_boxes(image_path):
    from ocr_engine import _pytesseract
    pytesseract = _pytesseract()
    from PIL import Image

    data = pytesseract.image_to_data(Image.open(image_path), config="--psm 11",
                                     output_type=pytesseract.Output.DICT)
    boxes = []
    for i, text in enumerate(data["text"]):
        if str(text).strip():
            x, y, w, h = data["left"][i], data["top"][i], data["width"][i], data["height"][i]
            conf = float(data["conf"][i])
            boxes.append((x, y, x + w, y + h, str(text), max(0.0, conf) / 100.0))
    return boxes


def ocr_engines_available():
    names = []
    try:
        import ocr_engine
        if ocr_engine.get_ocr() is not None:
            names.append("paddleocr")
    except Exception:
        pass
    try:
        from ocr_engine import _pytesseract
        pytesseract = _pytesseract()
        pytesseract.get_tesseract_version()
        names.append("tesseract")
    except Exception:
        pass
    return names


def page_words(image_path, engine=None):
    """OCR words of a page picture. engine: 'paddleocr', 'tesseract' or None (best available)."""
    order = [engine] if engine else ocr_engines_available()
    errors = []
    for name in order:
        try:
            boxes = _paddle_boxes(image_path) if name == "paddleocr" else _tesseract_boxes(image_path)
            return boxes, name
        except Exception as exc:  # try the next engine
            errors.append(f"{name}: {exc}")
    raise RuntimeError("; ".join(errors) or "no OCR engine is installed (PaddleOCR or Tesseract)")


# ------------------------------------------------------------------ table lines

def _cluster(values, gap):
    groups = []
    for v in sorted(values):
        if groups and v - groups[-1][-1] <= gap:
            groups[-1].append(v)
        else:
            groups.append([v])
    return [int(round(statistics.mean(g))) for g in groups]


def find_grid(gray):
    """
    Printed table lines of a page picture (numpy grayscale).
    Returns (rows, cols, angle): y positions of horizontal lines, x positions of vertical lines, and the tilt
    that was corrected (degrees). The returned positions are for the straightened picture.
    """
    import cv2
    import numpy as np

    def masks(img):
        h, w = img.shape
        bw = cv2.adaptiveThreshold(255 - img, 255, cv2.ADAPTIVE_THRESH_MEAN_C, cv2.THRESH_BINARY, 15, -8)
        hk = cv2.getStructuringElement(cv2.MORPH_RECT, (max(20, w // 40), 1))
        vk = cv2.getStructuringElement(cv2.MORPH_RECT, (1, max(20, h // 50)))
        horizontal = cv2.dilate(cv2.erode(bw, hk), hk)
        vertical = cv2.dilate(cv2.erode(bw, vk), vk)
        return horizontal, vertical

    # 1. tilt: the median angle of long horizontal lines
    horizontal, _ = masks(gray)
    lines = cv2.HoughLinesP(horizontal, 1, np.pi / 1800, threshold=200,
                            minLineLength=gray.shape[1] // 4, maxLineGap=20)
    angle = 0.0
    if lines is not None:
        segments = np.asarray(lines).reshape(-1, 4)   # OpenCV 4 gives (N,1,4), OpenCV 5 (N,4)
        angles = [np.degrees(np.arctan2(y2 - y1, x2 - x1)) for x1, y1, x2, y2 in segments]
        angles = [a for a in angles if abs(a) < 5]
        if angles:
            angle = float(np.median(angles))
    if abs(angle) > 0.15:
        h, w = gray.shape
        matrix = cv2.getRotationMatrix2D((w / 2, h / 2), angle, 1.0)
        gray = cv2.warpAffine(gray, matrix, (w, h), flags=cv2.INTER_LINEAR, borderValue=255)
    else:
        angle = 0.0

    # 2. lines as peaks of the projections
    horizontal, vertical = masks(gray)
    h, w = gray.shape
    rows = _cluster(list(np.where((horizontal > 0).sum(1) > w * 0.30)[0]), 8)
    if len(rows) >= 2 and rows[-1] - rows[0] > 40:
        # Column lines are measured only between the first and the last row line, so a short table
        # (e.g. the last page with two students) still shows its columns.
        band = vertical[rows[0]:rows[-1], :]
        cols = _cluster(list(np.where((band > 0).sum(0) > (rows[-1] - rows[0]) * 0.6)[0]), 8)
    else:
        cols = _cluster(list(np.where((vertical > 0).sum(0) > h * 0.20)[0]), 8)
    return rows, cols, angle, gray


def fit_columns(found, pattern, width):
    """
    Fit the form's known column pattern (0-1) to the vertical lines found on a page.
    A faint or cut scan can hide some lines, and a double border adds one; fitting a scale and an offset
    to the lines that ARE found gives every column border. -> (spans, lines matched) or (None, 0).
    """
    tol = max(6.0, width * 0.004)
    best = (0, None)
    found = sorted(found)
    for i in range(len(found)):
        for j in range(i + 1, len(found)):
            for a in range(len(pattern)):
                for b in range(a + 1, len(pattern)):
                    scale = (found[j] - found[i]) / (pattern[b] - pattern[a])
                    if scale < width * 0.5 or scale > width * 1.05:
                        continue
                    offset = found[i] - scale * pattern[a]
                    lines = [offset + scale * p for p in pattern]
                    hits = sum(1 for x in lines if any(abs(x - f) <= tol for f in found))
                    if hits > best[0]:
                        best = (hits, lines)
    hits, lines = best
    if not lines or hits < 4:
        return None, hits
    # snap each border to the line actually found next to it
    snapped = []
    for x in lines:
        near = [f for f in found if abs(x - f) <= tol]
        snapped.append(min(near, key=lambda f: abs(x - f)) if near else x)
    snapped[0] = max(0.0, snapped[0])
    return list(zip(snapped, snapped[1:])), hits


# ------------------------------------------------------------------ field cleaning

def _digits(text):
    return re.sub(r"\D", "", str(text).translate(DIGIT_FIX))


def clean_field(field, raw, ocr_conf=1.0):
    """
    -> (value, confidence 0-1, note or "")
    Strict fields are fixed only when the result has the exact expected shape; the note says what changed.
    """
    raw = re.sub(r"\s+", " ", str(raw or "")).strip()
    conf = ocr_conf
    note = ""
    if field in ("control_number",):
        d = _digits(raw)
        if re.fullmatch(r"\d{5}", d):
            note = "" if d == raw else f"read as '{raw}'"
            return d, conf if not note else min(conf, 0.85), note
        return raw, 0.3, "not a 5-digit control number" if raw else "missing"
    if field == "student_id":
        compact = re.sub(r"[\s.]", "", raw)
        m = re.fullmatch(r"(.{3,4})[-–—~_]?(.{5})", compact)
        if m:
            a, b = _digits(m.group(1)), _digits(m.group(2))
            if len(a) in (3, 4) and len(b) == 5:
                value = f"{a}-{b}"
                note = "" if value == compact else f"read as '{raw}'"
                return value, conf if not note else min(conf, 0.85), note
        m = re.search(r"(\d{3,4})\s*[-–—~_ ]\s*(\d{5})", raw.translate(DIGIT_FIX))
        if m:   # extra marks around the number (a pen tick, a stray letter)
            return f"{m.group(1)}-{m.group(2)}", min(conf, 0.7), f"read as '{raw}'"
        return raw, 0.3, "not in the NNN-NNNNN format" if raw else "missing"
    if field == "award_number":
        compact = re.sub(r"\s", "", raw)
        compact = re.sub(r"^[^0-9]{1,4}?(?=[-\d])", "TES", compact)       # lES- / rEs- / TE5- -> TES
        parts = [p for p in re.split(r"[-–—~_]+", compact) if p]
        if parts and parts[0].upper() != "TES":
            parts = ["TES"] + parts
        digits = [_digits(p) for p in parts[1:]]
        if len(digits) == 3 and [len(x) for x in digits] == [8, 12, 6]:
            value = "TES-" + "-".join(digits)
            note = "" if value == compact else f"read as '{raw}'"
            return value, conf if not note else min(conf, 0.85), note
        joined = "".join(digits)
        if len(joined) == 26:
            value = f"TES-{joined[:8]}-{joined[8:20]}-{joined[20:]}"
            return value, min(conf, 0.75), f"read as '{raw}'"
        return raw, 0.3, "award number incomplete or unreadable" if raw else "missing"
    if field in ("amount", "total_amount"):
        cleaned = raw.translate(DIGIT_FIX).replace(" ", "")
        m = re.fullmatch(r"(?:PHP|P|₱)?(\d{1,3}(?:[,.]\d{3})*|\d+)[.,](\d{2})", cleaned, re.I)
        if m:
            whole = re.sub(r"[,.]", "", m.group(1))
            value = f"{int(whole):,}.{m.group(2)}"
            note = "" if value == raw else f"read as '{raw}'"
            return value, conf if not note else min(conf, 0.85), note
        return raw, 0.3, "not an amount" if raw else "missing"
    if field == "sex":
        letters = re.sub(r"[^A-Z]", "", raw.upper())
        if letters in ("MALE", "FEMALE", "M", "F"):
            value = {"M": "MALE", "F": "FEMALE"}.get(letters, letters)
            # a stray mark next to the word (e.g. "]FEMALE" read as "MALE") lowers the trust
            return value, conf if re.fullmatch(r"[A-Za-z]+", raw) else min(conf, 0.7), ""
        if "EMALE" in letters or letters.startswith("FE") or letters.startswith("FM"):
            return "FEMALE", min(conf, 0.6), f"read as '{raw}'"
        if letters.endswith("ALE") or letters.startswith("MA"):
            return "MALE", min(conf, 0.6), f"read as '{raw}'"
        return raw, 0.3, "not MALE or FEMALE" if raw else "missing"
    if field in ("year_level", "batch"):
        d = _digits(raw)
        if re.fullmatch(r"[1-6]", d):
            return d, conf if d == raw else min(conf, 0.85), "" if d == raw else f"read as '{raw}'"
        return raw, 0.3, "not a number from 1 to 6" if raw else "missing"
    if field == "pwd":
        letters = re.sub(r"[^A-Z]", "", raw.upper())
        if letters in ("NO", "N", "NA", "NONE") or letters.startswith("NO"):
            return "No", conf, ""
        if letters in ("YES", "Y") or letters.startswith("YE"):
            return "Yes", conf, ""
        return raw, 0.3, "not Yes or No" if raw else "missing"
    if field == "phone":
        d = _digits(raw)
        if d.startswith("63") and len(d) == 12:
            d = "0" + d[2:]
        if re.fullmatch(r"09\d{9}", d):
            return d, conf if d == raw else min(conf, 0.9), "" if d == raw else f"read as '{raw}'"
        return raw, 0.4, "not an 11-digit 09 number" if raw else ""
    if field == "email":
        value = raw.replace(" ", "").lower().replace(",", ".")
        value = re.sub(r"@gmai[l1i]?[.,]?c[o0a]m$", "@gmail.com", value)
        value = re.sub(r"@gmail(?!\.)com$", "@gmail.com", value)
        if re.fullmatch(r"[a-z0-9._%+-]+@[a-z0-9-]+(\.[a-z0-9-]+)+", value):
            return value, conf if value == raw else min(conf, 0.85), "" if value == raw else f"read as '{raw}'"
        return raw, 0.4, "e-mail address unreadable" if raw else ""
    if field == "birthdate":
        return raw, conf if raw else 1.0, ""
    # names and program: never corrected; only flagged when they look wrong
    value = raw.upper().strip(" ,;:|")
    if field == "program":
        value = value.rstrip(".")       # a dot from the scan after the last word
    bad = re.search(r"[0-9@#$%^&*_=+<>{}\[\]\\|~]", value)
    if field in ("last", "first") and not value:
        return value, 0.3, "missing"
    if field == "middle" and not value:
        return value, 0.6, "empty: the form writes NA when there is none"
    if bad:
        return value, min(conf, 0.5), "contains characters that are not letters"
    return value, conf, ""


# ------------------------------------------------------------------ page -> rows

def _assign(words, row_spans, col_spans):
    """Put each OCR word in the cell its centre falls into. -> {(r, c): [words]}"""
    cells = {}
    for w in words:
        cx, cy = (w[0] + w[2]) / 2, (w[1] + w[3]) / 2
        r = next((i for i, (a, b) in enumerate(row_spans) if a <= cy < b), None)
        c = next((i for i, (a, b) in enumerate(col_spans) if a <= cx < b), None)
        if r is not None and c is not None:
            cells.setdefault((r, c), []).append(w)
    return cells


def _cell_text(words):
    if not words:
        return "", 1.0
    h = statistics.median([max(1.0, w[3] - w[1]) for w in words])
    lines = []
    for w in sorted(words, key=lambda w: ((w[1] + w[3]) / 2, w[0])):
        cy = (w[1] + w[3]) / 2
        if lines and abs(cy - lines[-1][0]) <= h * 0.6:
            lines[-1][1].append(w)
        else:
            lines.append([cy, [w]])
    text_lines = [" ".join(x[4] for x in sorted(line, key=lambda w: w[0])) for _, line in lines]
    # wrapped pieces of one value: "TES-20240808-" + "172600000000-" + "027269" -> no spaces after '-'
    text = ""
    for part in text_lines:
        if text and (text.endswith("-") or part.startswith("-")):
            text += part
        else:
            text = (text + " " + part).strip()
    confs = [w[5] for w in words]
    return text, (min(confs) if confs else 1.0)


def read_page(image_path, template_name, engine=None, words=None, fallback_spans=None):
    """
    One page picture -> dict(rows=[{field: (value, conf, note)}], head=row continued from the previous
    page or None, text=all words outside the table, engine, angle, columns_found).
    """
    import cv2

    template = RULED_TEMPLATES[template_name]
    columns = template["columns"]
    gray = cv2.imread(image_path, cv2.IMREAD_GRAYSCALE)
    rows_y, cols_x, angle, straight = find_grid(gray)

    straight_path = image_path
    if angle:
        fd, straight_path = tempfile.mkstemp(suffix=".png")
        os.close(fd)
        cv2.imwrite(straight_path, straight)
    try:
        if words is None:
            words, engine = page_words(straight_path, engine)
        else:
            engine = "given"
            if angle:      # the given words belong to the tilted picture: move them like the picture
                import numpy as np
                hh, ww = gray.shape
                m = cv2.getRotationMatrix2D((ww / 2, hh / 2), angle, 1.0)
                moved = []
                for x0, y0, x1, y1, text, conf in words:
                    cx, cy = (x0 + x1) / 2, (y0 + y1) / 2
                    nx, ny = m @ np.array([cx, cy, 1.0])
                    hw, hh2 = (x1 - x0) / 2, (y1 - y0) / 2
                    moved.append((nx - hw, ny - hh2, nx + hw, ny + hh2, text, conf))
                words = moved
    finally:
        if straight_path != image_path:
            os.remove(straight_path)

    h, w = straight.shape
    col_spans, matched = fit_columns(cols_x, template["lines"], w)
    borrowed = False
    if not col_spans and fallback_spans and len(rows_y) >= 2:
        # e.g. the last page holds only the end of one row and the totals: no column lines to measure.
        col_spans, borrowed = fallback_spans, True
    result = {"rows": [], "head": None, "outside": [], "engine": engine, "angle": round(angle, 2),
              "columns_found": matched, "row_lines": len(rows_y), "col_spans": col_spans, "borrowed": borrowed}
    if not col_spans or len(rows_y) < 2:
        result["outside"] = words
        return result

    row_spans = list(zip(rows_y, rows_y[1:]))
    typical = statistics.median([b - a for a, b in row_spans]) if row_spans else 0
    # A faint scan can hide a line between two students: split a row that is two or three rows tall.
    fixed = []
    for a, b in row_spans:
        parts = round((b - a) / typical) if typical else 1
        if 2 <= parts <= 3 and (b - a) > typical * 1.6:
            step = (b - a) / parts
            fixed.extend((a + k * step, a + (k + 1) * step) for k in range(parts))
        else:
            fixed.append((a, b))
    row_spans = [(a, b) for a, b in fixed if b - a > typical * 0.45]
    top_band = (max(0, rows_y[0] - typical), rows_y[0])
    # the last row can be cut by the bottom edge of the scan (no line under it)
    bottom_band = (rows_y[-1], min(h, rows_y[-1] + typical))
    spans = [top_band] + row_spans + ([bottom_band] if bottom_band[1] - bottom_band[0] > typical * 0.45 else [])
    cells = _assign(words, spans, col_spans)

    # Words above the first line (band 0) also stay in the "outside" text: on the first page that band holds
    # titles such as "HEI Campus Name", which the file details are read from.
    if borrowed:
        inside = set()
    else:
        inside = {id(x) for (r, _c), ws in cells.items() if r > 0 for x in ws}
    result["outside"] = [x for x in words if id(x) not in inside]

    for r in range(len(spans)):
        raw = {columns[c]: _cell_text(cells.get((r, c), [])) for c in range(len(columns))}
        if not any(text for text, _ in raw.values()):
            continue
        if any(re.search(r"control\s*number|student\s*number|award\s*number|given\s*name", t, re.I)
               for t, _ in raw.values()):
            continue      # the header row
        # strict fields that came out empty or in the wrong shape: read that one cell again, enlarged
        for c, field in enumerate(columns):
            text, conf = raw[field]
            if result["engine"] not in ("paddleocr", "tesseract"):
                break        # words were given (tests): nothing to read again
            if (field in NEEDED and not text) or (field in STRICT and clean_field(field, text, conf)[1] < LOW):
                again = _ocr_cell(straight, spans[r], col_spans[c], result["engine"], field)
                if again and (not text or clean_field(field, again[0], again[1])[1] > clean_field(field, text, conf)[1]):
                    raw[field] = again
        key_text = raw[template["key"]][0]
        has_key = len(re.sub(r"\D", "", key_text)) >= 3      # a real control number has digits, not "HEI"
        if borrowed and r > 0:
            break      # columns measured on another page: only the continued row is trusted, nothing else
        if r == 0:
            # above the first line: the end of a row that started on the previous page
            if not has_key:
                if borrowed:
                    raw = {f: (t, min(c, 0.6)) for f, (t, c) in raw.items()}
                result["head"] = raw
                continue
            if typical and spans[0][1] - spans[0][0] < typical * 0.8:
                continue   # a cut-off row at the top edge that has its own control number: not reliable
        # every ruled row is one student, even when its control number cannot be read
        result["rows"].append(raw)
    return result


STRICT = ("control_number", "student_id", "sex", "year_level", "batch", "amount", "pwd", "total_amount", "phone")
NEEDED = ("last", "first", "middle", "program", "award_number")
SINGLE = {"year_level": "0123456789", "batch": "0123456789", "control_number": "0123456789",
          "pwd": "YESNOyesno"}


def _ocr_cell(gray, row_span, col_span, engine, field=""):
    """Read one table cell again on its own (enlarged). -> (text, confidence) or None."""
    import cv2

    # a small margin, then any printed border line still in the crop is painted white (lines confuse OCR,
    # and a wide margin would cut off the first letter of text written close to the line)
    my = min(12, max(6, int((row_span[1] - row_span[0]) * 0.06)))
    mx = min(12, max(8, int((col_span[1] - col_span[0]) * 0.06)))
    y0, y1 = int(row_span[0]) + my, int(row_span[1]) - my
    x0, x1 = int(col_span[0]) + mx, int(col_span[1]) - mx
    if y1 - y0 < 8 or x1 - x0 < 8:
        return None
    crop = gray[y0:y1, x0:x1].copy()
    ink = cv2.threshold(crop, 0, 255, cv2.THRESH_BINARY_INV + cv2.THRESH_OTSU)[1]
    for size in ((max(15, crop.shape[1] // 2), 1), (1, max(15, int(crop.shape[0] * 0.8)))):
        lines = cv2.morphologyEx(ink, cv2.MORPH_OPEN, cv2.getStructuringElement(cv2.MORPH_RECT, size))
        crop[lines > 0] = 255
    crop = cv2.resize(crop, None, fx=2, fy=2, interpolation=cv2.INTER_CUBIC)
    crop = cv2.copyMakeBorder(crop, 20, 20, 20, 20, cv2.BORDER_CONSTANT, value=255)
    fd, path = tempfile.mkstemp(suffix=".png")
    os.close(fd)
    try:
        cv2.imwrite(path, crop)
        if engine == "paddleocr":
            boxes = _paddle_boxes(path)
        else:
            from ocr_engine import _pytesseract
            pytesseract = _pytesseract()
            from PIL import Image

            config = "--psm 6"
            if field in SINGLE:
                config = f"--psm 7 -c tessedit_char_whitelist={SINGLE[field]}"
            data = pytesseract.image_to_data(Image.open(path), config=config,
                                             output_type=pytesseract.Output.DICT)
            # Tesseract gives -1 or 0 when it read a single short word without a score: treat as "unsure"
            boxes = [(data["left"][i], data["top"][i], data["left"][i] + data["width"][i],
                      data["top"][i] + data["height"][i], t,
                      float(data["conf"][i]) / 100 if float(data["conf"][i]) > 0 else 0.6)
                     for i, t in enumerate(data["text"]) if str(t).strip()]
        return _cell_text(boxes) if boxes else None
    except Exception:
        return None
    finally:
        os.remove(path)


# ------------------------------------------------------------------ whole document

def _render_pages(path, dpi=300):
    """Yield (page number, png path, page text layer) for every page of a PDF, or the picture itself."""
    if isinstance(path, (list, tuple)):          # several page pictures (photos of each page)
        for number, item in enumerate(path, start=1):
            yield number, item, ""
        return
    ext = os.path.splitext(path)[1].lower()
    if ext in (".png", ".jpg", ".jpeg"):
        yield 1, path, ""
        return
    try:
        import pymupdf as fitz
    except ImportError:
        import fitz
    with fitz.open(path) as doc:
        if getattr(doc, "needs_pass", False):
            raise ValueError("the PDF is password-protected; ask for a copy without a password")
        for number, page in enumerate(doc, start=1):
            pix = page.get_pixmap(dpi=dpi)
            fd, png = tempfile.mkstemp(suffix=".png")
            os.close(fd)
            pix.save(png)
            try:
                yield number, png, page.get_text("text")
            finally:
                os.remove(png)


def _money(text):
    m = re.search(r"(\d{1,3}(?:[,.]\d{3})+|\d+)[.,](\d{2})(?!\d)", text.translate(DIGIT_FIX).replace(" ", ""))
    return float(re.sub(r"[,.]", "", m.group(1)) + "." + m.group(2)) if m else None


def extract_form_details(text):
    """Title-page details (review only). Missing values stay None."""
    t = re.sub(r"\s+", " ", text or "")
    details = {"program": None, "batch": None, "semester": None, "academic_year": None, "campus": None,
               "printed_total": None}
    if re.search(r"\bTES\b|tertiary education subsidy", t, re.I):
        details["program"] = "TES"
    m = re.search(r"\bbatch\s*[:#-]?\s*(\d{1,2})\b", t, re.I)
    if m:
        details["batch"] = m.group(1)
    m = re.search(r"\b(first|1st|second|2nd)\s+sem", t, re.I) or re.search(r"\b(summer|midyear)\b", t, re.I)
    if m:
        v = m.group(1).lower()
        details["semester"] = "1st Semester" if v in ("first", "1st") else (
            "2nd Semester" if v in ("second", "2nd") else "Summer")
    years = [(int(a), int(b)) for a, b in re.findall(r"\b(20\d{2})\s*[-–]\s*(20\d{2})\b", t)]
    good = [f"{a}-{b}" for a, b in years if b == a + 1]
    if good:
        details["academic_year"] = max(set(good), key=good.count)
    m = re.search(r"((?:[A-Z][A-Za-z.']*\s+){1,5}(?:University|College)\s*[-–]\s*[A-Za-z ]+?Campus)", t)
    if m:
        campus = re.sub(r"\s+", " ", m.group(1)).strip()
        campus = re.sub(r"^(?:HEI\s+)?Campus\s+Name\s*:?\s*", "", campus, flags=re.I)
        details["campus"] = re.sub(r"^(?:(?:Republic|of|the|Philippines)\s+)+", "", campus, flags=re.I)
    totals = []
    for m in re.finditer(r"(total\s+tertiary\s+education\s+subsidy|total\s+tes\s+billing[a-z ]*|total\s+amount)"
                         r"\s*[:\-]?\s*(?:PHP|₱)?\s*([0-9OoIl,.\s]{6,20})", t, re.I):
        value = _money(m.group(2))
        if value:
            totals.append(value)
    if totals:
        details["printed_total"] = max(totals)
    return details


def read_document(path, template_name="tes_continuing_form_2_annex_2", engine=None, dpi=300, page_words_fn=None):
    """
    The whole TES billing file -> {
        "header": [16 column titles], "rows": [[16 values]], "cells": [[{"conf", "note"}]],
        "warnings": [...], "metadata": {...}, "engine": "paddleocr" | "tesseract", "pages": [...]
    }
    page_words_fn(png_path) -> words is only used by tests.
    """
    template = RULED_TEMPLATES[template_name]
    columns = template["columns"]
    all_rows, pages, outside_text, engine_used, last_spans = [], [], [], None, None
    orphan_heads = []

    for number, png, layer in _render_pages(path, dpi):
        words = page_words_fn(png) if page_words_fn else None
        page = read_page(png, template_name, engine=engine, words=words, fallback_spans=last_spans)
        engine_used = engine_used or page["engine"]
        if page["col_spans"] and not page["borrowed"]:
            last_spans = page["col_spans"]
        continued = False
        if page["head"] and all_rows:
            # Join the end of a row to the last row of the page before ONLY when that row is really
            # unfinished (e.g. its award number is cut). If a page is missing, the end belongs to a row
            # we never saw, and joining it would mix two students.
            prev = all_rows[-1]
            unfinished = [f for f, (t, c) in page["head"].items()
                          if t and clean_field(f, prev[f][0], prev[f][1])[1] < LOW]
            key = template["key"]
            prev_no = clean_field(key, *prev[key])[0]
            next_no = clean_field(key, *page["rows"][0][key])[0] if page["rows"] else ""
            in_order = not (prev_no.isdigit() and next_no.isdigit()) or int(next_no) == int(prev_no) + 1
            if unfinished and in_order:
                continued = True
                for field, (text, conf) in page["head"].items():
                    if text:
                        old, old_conf = prev[field]
                        joiner = "" if old.endswith("-") else " "
                        prev[field] = ((old + joiner + text).strip(), min(old_conf, conf))
            else:
                orphan_heads.append(number)
        all_rows.extend(page["rows"])
        outside_text.append(" ".join(w[4] for w in sorted(page["outside"], key=lambda w: (w[1] // 20, w[0]))))
        pages.append({"page": number, "rows": len(page["rows"]), "columns_found": page["columns_found"],
                      "tilt_corrected": page["angle"], "continued_row": continued})

    # clean every field
    out_rows, out_cells = [], []
    for raw in all_rows:
        values, info = [], []
        for field in columns:
            text, conf = raw[field]
            value, c, note = clean_field(field, text, conf)
            values.append(value)
            info.append({"conf": round(c, 2), "note": note, "check": c < LOW})
        out_rows.append(values)
        out_cells.append(info)

    metadata = extract_form_details(" ".join(outside_text))
    _use_whole_file(out_rows, out_cells, metadata, columns)
    warnings = check_rows(out_rows, metadata, columns)
    for number in orphan_heads:
        warnings.append(f"Page {number} starts with the end of a row whose beginning is not in the file "
                        "(a page before it may be missing). That piece was left out.")
    for p in pages[1:-1] if len(pages) > 2 else pages:
        if p["rows"] == 0:
            warnings.append(f"Page {p['page']}: no student rows were found. If students are listed on this page, "
                            "the scan may be too faint or tilted: check that page by hand.")
    flagged = sum(1 for row in out_cells for c in row if c["check"])
    return {
        "template": template_name,
        "header": list(template["header"]),
        "rows": out_rows,
        "cells": out_cells,
        "warnings": warnings,
        "metadata": metadata | {"rows_read": len(out_rows), "cells_to_check": flagged},
        "engine": engine_used,
        "pages": pages,
    }


def _use_whole_file(rows, cells, metadata, columns):
    """Use what the whole file says to settle single cells. Every change keeps a note for staff."""
    idx = {name: i for i, name in enumerate(columns)}
    # 1. the amount every grantee gets: a cell that shows exactly that amount is fine
    for key in ("amount", "total_amount"):
        i = idx[key]
        valid = [r[i] for r in rows if re.fullmatch(r"[\d,]+\.\d{2}", r[i])]
        if valid:
            common = statistics.mode(valid)
            if valid.count(common) >= len(rows) * 0.6:
                for r, c in zip(rows, cells):
                    if r[i] == common and c[i]["check"]:
                        c[i].update(conf=0.9, check=False)
    # 2. the batch is printed in the title
    if metadata.get("batch"):
        i = idx["batch"]
        for r, c in zip(rows, cells):
            if r[i] == metadata["batch"]:
                c[i].update(conf=max(c[i]["conf"], 0.9), check=False)
            elif not r[i]:
                r[i] = metadata["batch"]
                c[i].update(conf=0.85, check=False, note="empty on the scan; taken from the title (TES Batch)")
    # 3. numbers that are built the same way in every row (Student Number "NNN-0NNNN", the award number's
    #    "TES-20240808-172600000000-" part): a digit that differs from what almost every other row has is
    #    probably misread, so it is highlighted (not changed).
    for key in ("student_id", "award_number"):
        i = idx[key]
        shape = r"\d{3,4}-\d{5}" if key == "student_id" else r"TES-\d{8}-\d{12}-\d{6}"
        values = [r[i] for r in rows if re.fullmatch(shape, r[i] or "")]   # only well-formed values vote
        if len(values) < 10:
            continue
        width = max(len(v) for v in values)
        for pos in range(width):
            chars = [v[pos] for v in values if len(v) > pos]
            common = max(set(chars), key=chars.count)
            if chars.count(common) < len(chars) * 0.85:
                continue          # this position really varies (e.g. the year part of a Student Number)
            for r, c in zip(rows, cells):
                v = r[i]
                if v and re.fullmatch(shape, v) and len(v) > pos and v[pos] != common:
                    c[i].update(conf=min(c[i]["conf"], 0.6), check=True,
                                note=(c[i]["note"] + "; " if c[i]["note"] else "") +
                                f"character {pos + 1} is '{v[pos]}' but almost every other row has '{common}' here")
    # 4. a control number between two readable ones that differ by 2
    i = idx["control_number"]
    for k in range(1, len(rows) - 1):
        if not re.fullmatch(r"\d{5}", rows[k][i]):
            before, after = rows[k - 1][i], rows[k + 1][i]
            if re.fullmatch(r"\d{5}", before) and re.fullmatch(r"\d{5}", after) and int(after) - int(before) == 2:
                was = rows[k][i]
                rows[k][i] = f"{int(before) + 1:05d}"
                cells[k][i].update(conf=0.7, check=True,
                                   note=f"filled in from the order of the list (scan shows '{was}')")


def check_rows(rows, metadata, columns=TES_COLUMNS):
    """Whole-file checks; warnings only (staff decide)."""
    idx = {name: i for i, name in enumerate(columns)}
    warnings = []
    controls = [r[idx["control_number"]] for r in rows if re.fullmatch(r"\d{5}", r[idx["control_number"]])]
    numbers = [int(c) for c in controls]
    seen, dups = set(), set()
    for c in controls:
        (dups if c in seen else seen).add(c)
    if dups:
        warnings.append("The same control number appears twice: " + ", ".join(sorted(dups)) + ".")
    if numbers:
        missing = []
        for a, b in zip(numbers, numbers[1:]):
            if b > a + 1:
                missing.append(f"{a + 1:05d}" if b == a + 2 else f"{a + 1:05d}–{b - 1:05d}")
            elif b <= a:
                warnings.append(f"Control numbers are out of order near {a:05d} → {b:05d}.")
        if missing:
            warnings.append("Control numbers are missing: " + ", ".join(missing) +
                            ". A page may be missing from the scan, or a row could not be read.")
    shapes = {"student_id": r"\d{3,4}-\d{5}", "award_number": r"TES-\d{8}-\d{12}-\d{6}"}
    for key, label in (("student_id", "Student Number"), ("award_number", "TES Award Number")):
        values = [r[idx[key]] for r in rows if re.fullmatch(shapes[key], r[idx[key]])]
        repeated = sorted({v for v in values if values.count(v) > 1})
        if repeated:
            warnings.append(f"The same {label} appears more than once: " + ", ".join(repeated) + ".")
    bad_ids = sum(1 for r in rows if not re.fullmatch(r"\d{3,4}-\d{5}", r[idx["student_id"]]))
    if bad_ids:
        warnings.append(f"{bad_ids} row(s) have a Student Number that could not be read in the NNN-NNNNN format.")

    amounts = []
    for r in rows:
        try:
            amounts.append(float(r[idx["total_amount"]].replace(",", "")))
        except ValueError:
            pass
    summed = round(sum(amounts), 2)
    printed = metadata.get("printed_total")
    per = None
    if amounts:
        per = statistics.mode(amounts)
    if printed:
        if abs(summed - printed) > 0.01:
            warnings.append(f"The rows add up to {summed:,.2f}, but the file's printed total is {printed:,.2f}.")
        if per:
            expected = printed / per
            if abs(expected - round(expected)) < 1e-6 and int(round(expected)) != len(rows):
                warnings.append(f"The printed total {printed:,.2f} ÷ {per:,.2f} = {int(round(expected))} grantees, "
                                f"but {len(rows)} rows were read.")
    metadata["summed_amount"] = summed
    if printed and per:
        metadata["expected_count"] = int(round(printed / per))
    return warnings


# ------------------------------------------------------------------ any ruled table (no known form)

def read_generic(path, engine=None, dpi=300, min_rows=2, min_cols=2):
    """
    Any scanned list printed as a table with lines (most agency lists are): the cells come from the lines,
    the words from OCR, and a cell that came out empty or unsure is read again on its own.
    -> {"rows": [[text]], "cells": [[{conf, note, check}]], "engine": str, "pages": n} or None when no table
    with lines was found (the caller then falls back to reading words by position).
    """
    import cv2

    all_rows, all_cells, used, pages, width = [], [], None, 0, None
    skipped, per_page, total = [], {}, 0
    for number, png, _layer in _render_pages(path, dpi):
        total += 1
        gray = cv2.imread(png, cv2.IMREAD_GRAYSCALE)
        rows_y, cols_x, angle, straight = find_grid(gray)
        h, w = straight.shape
        col_spans = [(a, b) for a, b in zip(cols_x, cols_x[1:]) if b - a >= max(25, w // 80)]
        if len(rows_y) < min_rows + 1 or len(col_spans) < min_cols:
            skipped.append(number)   # reported to staff: never dropped silently
            continue
        straight_path = png
        if angle:
            fd, straight_path = tempfile.mkstemp(suffix=".png")
            os.close(fd)
            cv2.imwrite(straight_path, straight)
        try:
            words, used = page_words(straight_path, engine)
        finally:
            if straight_path != png:
                os.remove(straight_path)
        pages += 1
        row_spans = list(zip(rows_y, rows_y[1:]))
        cells = _assign(words, row_spans, col_spans)
        for r, span in enumerate(row_spans):
            texts, infos = [], []
            for c, col in enumerate(col_spans):
                text, conf = _cell_text(cells.get((r, c), []))
                if not text or conf < LOW:
                    again = _ocr_cell(straight, span, col, used)
                    if again and (not text or again[1] > conf):
                        text, conf = again
                text = re.sub(r"\s+", " ", text).strip(" |")
                texts.append(text)
                infos.append({"conf": round(conf, 2), "note": "", "check": bool(text) and conf < LOW})
            if any(texts):
                if width is None:
                    width = len(texts)
                all_rows.append(texts)
                all_cells.append(infos)
                per_page[number] = per_page.get(number, 0) + 1
    if not all_rows:
        return None
    # pages with a different number of columns are padded so every row has the same width
    widest = max(len(r) for r in all_rows)
    for r, c in zip(all_rows, all_cells):
        r.extend([""] * (widest - len(r)))
        c.extend([{"conf": 1.0, "note": "", "check": False}] * (widest - len(c)))
    # a header repeated on every page is kept only once
    header = [t.lower() for t in all_rows[0]]
    keep = [0] + [i for i in range(1, len(all_rows)) if [t.lower() for t in all_rows[i]] != header]
    return {"rows": [all_rows[i] for i in keep], "cells": [all_cells[i] for i in keep], "engine": used, "pages": pages,
            "total_pages": total, "skipped_pages": skipped, "rows_per_page": per_page}
