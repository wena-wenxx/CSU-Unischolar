"""
"Possible edit" cautions for uploaded documents (ASSISTIVE ONLY).

Reads hidden details INSIDE the file (not the text on it):
    PDF      which program made or last saved it (Creator / Producer),
             the created and changed dates, and how many times the file
             was saved again after it was first made
    picture  the "Software" field (e.g. Photoshop) a photo editor writes

These signs are NOT proof. A genuine certificate can be saved in an editor,
and a well-made fake can show none of them. They only tell OAS staff where
to look more closely; the document status is never changed because of them.
Real verification is done with the issuing office (PSA, PhilSys Check,
Registrar, barangay, BIR / employer).
"""
import re
from datetime import datetime

# Programs that are mainly used to EDIT existing pictures or PDFs.
# (Word, Excel and scanner software are normal for certificates, so they are not listed.)
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
    """'D:20260105143000+08'00'' -> '20260105143000' (or None)."""
    match = re.search(r"(\d{14})", value or "")
    return match.group(1) if match else None


def _readable(stamp):
    return f"{stamp[0:4]}-{stamp[4:6]}-{stamp[6:8]} {stamp[8:10]}:{stamp[10:12]}"


def pdf_notes(metadata, raw):
    """
    metadata: PyMuPDF doc.metadata (creator, producer, creationDate, modDate)
    raw:      the file's bytes
    Returns a list of plain-language cautions (empty = nothing unusual found).
    """
    notes = []
    metadata = metadata or {}

    for field in ("creator", "producer"):
        editor = _editor_in(metadata.get(field))
        if editor:
            notes.append(f"The file says it was made or saved with {metadata.get(field).strip()} (an editing program)." + NOT_PROOF)
            break

    created, changed = _pdf_date(metadata.get("creationDate")), _pdf_date(metadata.get("modDate"))
    if created and changed and changed > created:
        # More than a few minutes apart (a scanner writes both at once).
        try:
            minutes = (datetime.strptime(changed, "%Y%m%d%H%M%S") - datetime.strptime(created, "%Y%m%d%H%M%S")).total_seconds() / 60
        except ValueError:
            minutes = 0
        if minutes > 5:
            notes.append(f"The file was changed after it was first made (made {_readable(created)}, changed {_readable(changed)})." + NOT_PROOF)

    # Each "save again" adds an end-of-file marker. A "linearized" (fast web view) PDF
    # normally has two, so only count more than that as a sign of later edits.
    eof = raw.count(b"%%EOF") if raw else 0
    limit = 2 if b"/Linearized" in (raw or b"")[:2048] else 1
    if eof > limit:
        notes.append(f"The file was saved again {eof - limit} time(s) after it was first made." + NOT_PROOF)

    return notes


def image_notes(path):
    """Cautions for a JPG / PNG: the editing program written inside the picture, if any."""
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
    """Cautions for any uploaded document file. Never raises."""
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
