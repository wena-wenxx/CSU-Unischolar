"""
Text extraction.
- PDF with real text  -> read the text directly (fast, no OCR needed)
- Scanned PDF / image -> PaddleOCR (or Tesseract when PaddleOCR is not installed)
If neither is installed, the service still runs; images come back with
ocr_status = 'failed' and OAS staff review them manually.
"""
import os
import tempfile

try:
    import pymupdf as fitz  # PyMuPDF (new name)
except ImportError:
    import fitz  # PyMuPDF (old name)

_ocr = None
_ocr_error = None


def get_ocr():
    """Load PaddleOCR once (first call is slow: it downloads models)."""
    global _ocr, _ocr_error
    if _ocr is not None:
        return _ocr
    if _ocr_error is not None:
        return None
    try:
        from paddleocr import PaddleOCR
        try:  # PaddleOCR 3.x style
            _ocr = PaddleOCR(
                lang="en",
                use_doc_orientation_classify=False,
                use_doc_unwarping=False,
                use_textline_orientation=False,
            )
        except (TypeError, ValueError):  # PaddleOCR 2.x style
            _ocr = PaddleOCR(lang="en", use_angle_cls=True)
        return _ocr
    except Exception as e:  # not installed, or failed to load
        _ocr_error = f"{type(e).__name__}: {e}"
        return None


def ocr_status() -> dict:
    ocr = get_ocr()
    return {"paddleocr_ready": ocr is not None, "error": _ocr_error}


def collect_texts(result) -> list:
    """Read text out of PaddleOCR results (works for both 2.x and 3.x formats)."""
    texts = []
    for page in result or []:
        if page is None:
            continue
        try:  # 3.x: dict-like with 'rec_texts'
            texts.extend(list(page["rec_texts"]))
            continue
        except Exception:
            pass
        try:  # 3.x alternative: .json['res']['rec_texts']
            texts.extend(list(page.json["res"]["rec_texts"]))
            continue
        except Exception:
            pass
        if isinstance(page, list):  # 2.x: [[box, (text, score)], ...]
            for line in page:
                try:
                    texts.append(line[1][0])
                except Exception:
                    pass
    return texts


def _tesseract_cmd():
    """
    Where the Tesseract program is. On Windows the installer does not add it to PATH, so the usual
    folders are tried too. TESSERACT_CMD (environment variable) wins when it is set.
    """
    import shutil
    candidates = [
        os.environ.get("TESSERACT_CMD"),
        shutil.which("tesseract"),
        r"C:\Program Files\Tesseract-OCR\tesseract.exe",
        r"C:\Program Files (x86)\Tesseract-OCR\tesseract.exe",
        os.path.expandvars(r"%LOCALAPPDATA%\Programs\Tesseract-OCR\tesseract.exe"),
    ]
    for path in candidates:
        if path and os.path.isfile(path):
            return path
    return None


def _pytesseract():
    import pytesseract
    cmd = _tesseract_cmd()
    if cmd:
        pytesseract.pytesseract.tesseract_cmd = cmd
    return pytesseract


def _tesseract_text(path: str) -> str:
    """Second choice when PaddleOCR is not installed: Tesseract (needs the Tesseract program + pytesseract)."""
    pytesseract = _pytesseract()
    from PIL import Image, ImageOps

    image = ImageOps.exif_transpose(Image.open(path)).convert("L")
    if max(image.size) < 1800:          # small phone photos read better a little larger
        scale = 1800 / max(image.size)
        image = image.resize((int(image.width * scale), int(image.height * scale)))
    text = pytesseract.image_to_string(image, config="--psm 3")

    # Speckled or tinted phone photos can make Tesseract see the whole page as one picture and return
    # (almost) nothing. Reading the page in overlapping horizontal strips fixes that; the longer
    # result is kept.
    if len(text.strip()) < 800:
        strips, height = [], image.height
        step = max(1, height // 4)
        for i in range(4):
            top, bottom = max(0, i * step - 60), min(height, (i + 1) * step + 60)
            strips.append(pytesseract.image_to_string(image.crop((0, top, image.width, bottom)), config="--psm 6"))
        by_strips = "\n".join(strips)
        if len(by_strips.strip()) > len(text.strip()):
            text = by_strips
    return text


def ocr_engine_name() -> str:
    if get_ocr() is not None:
        return "paddleocr"
    try:
        _pytesseract().get_tesseract_version()
        return "tesseract"
    except Exception:
        return ""


def _ocr_image(path: str):
    ocr = get_ocr()
    if ocr is None:
        if ocr_engine_name() == "tesseract":
            return _tesseract_text(path)
        raise RuntimeError(_ocr_error or "PaddleOCR is not installed")
    try:
        if hasattr(ocr, "predict"):
            result = ocr.predict(path)
        else:
            result = ocr.ocr(path, cls=True)
    except Exception:
        result = ocr.ocr(path)
    return "\n".join(collect_texts(result))


def extract_text(path: str, filename: str = "") -> dict:
    """Returns {'text', 'engine', 'ocr_status', 'error'}."""
    ext = os.path.splitext(filename or path)[1].lower()

    try:
        if ext == ".pdf":
            doc = fitz.open(path)
            if getattr(doc, "needs_pass", False):
                return {"text": "", "engine": None, "ocr_status": "failed",
                        "error": "the PDF is password-protected; ask the student for a copy without a password"}
            parts, used_ocr = [], False
            for page in doc:
                text = page.get_text().strip()
                if len(text) >= 30:
                    parts.append(text)
                else:  # scanned page -> render to PNG and OCR it
                    pix = page.get_pixmap(dpi=200)
                    with tempfile.NamedTemporaryFile(suffix=".png", delete=False) as tmp:
                        tmp_path = tmp.name
                    try:
                        pix.save(tmp_path)
                        parts.append(_ocr_image(tmp_path))
                        used_ocr = True
                    finally:
                        os.remove(tmp_path)
            return {"text": "\n".join(parts), "engine": (ocr_engine_name() or "ocr") if used_ocr else "pdf-text",
                    "ocr_status": "ok", "error": None}

        # images
        return {"text": _ocr_image(path), "engine": ocr_engine_name() or "ocr", "ocr_status": "ok", "error": None}

    except Exception as e:
        return {"text": "", "engine": None, "ocr_status": "failed", "error": f"{type(e).__name__}: {e}"}
