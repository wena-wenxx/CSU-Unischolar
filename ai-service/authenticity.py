import re
from datetime import datetime

EDITORS = [
    "photoshop", "gimp", "canva", "pixlr", "paint.net", "affinity", "inkscape", "picsart", "snapseed",
    "ilovepdf", "smallpdf", "sejda", "pdfescape", "pdf-xchange editor", "pdfelement", "wondershare",
    "foxit phantompdf", "foxit pdf editor", "nitro pro", "pdf candy", "pdffiller", "dochub", "sodapdf",
]

NOT_PROOF = " This is not proof: compare it with the original or confirm with the issuing office."


def _editor_in(text):
    low = (text or "").lower()
    return next((name for name in EDITORS if name in low), None)


def _pdf_date(value):
    match = re.search(r"(\d{14})", value or "")
    return match.group(1) if match else None


def _readable(stamp):
    return f"{stamp[0:4]}-{stamp[4:6]}-{stamp[6:8]} {stamp[8:10]}:{stamp[10:12]}"


def pdf_notes(metadata, raw):
    notes = []
    metadata = metadata or {}

    for field in ("creator", "producer"):
        editor = _editor_in(metadata.get(field))
        if editor:
            notes.append(f"The file says it was made or saved with {metadata.get(field).strip()} (an editing program)." + NOT_PROOF)
            break

    created, changed = _pdf_date(metadata.get("creationDate")), _pdf_date(metadata.get("modDate"))
    if created and changed and changed > created:
        try:
            minutes = (datetime.strptime(changed, "%Y%m%d%H%M%S") - datetime.strptime(created, "%Y%m%d%H%M%S")).total_seconds() / 60
        except ValueError:
            minutes = 0
        if minutes > 5:
            notes.append(f"The file was changed after it was first made (made {_readable(created)}, changed {_readable(changed)})." + NOT_PROOF)

    eof = raw.count(b"%%EOF") if raw else 0
    limit = 2 if b"/Linearized" in (raw or b"")[:2048] else 1
    if eof > limit:
        notes.append(f"The file was saved again {eof - limit} time(s) after it was first made." + NOT_PROOF)

    return notes


def image_notes(path):
    try:
        from PIL import Image
    except ImportError:
        return []
    try:
        with Image.open(path) as image:
            software = image.info.get("Software") or image.getexif().get(305) or ""
    except Exception:
        return []
    editor = _editor_in(str(software))
    if editor:
        return [f"The picture says it was saved with {str(software).strip()} (an editing program)." + NOT_PROOF]
    return []


def file_notes(path, filename, raw):
    name = (filename or path).lower()
    try:
        if name.endswith(".pdf"):
            try:
                import pymupdf as fitz
            except ImportError:
                import fitz
            with fitz.open(path) as doc:
                return pdf_notes(doc.metadata, raw)
        if name.endswith((".jpg", ".jpeg", ".png")):
            return image_notes(path)
    except Exception:
        return []
    return []
