"""
Text extraction.
- PDF with real text  -> read the text directly (fast, no OCR needed)
- Scanned PDF / image -> PaddleOCR (or Tesseract when PaddleOCR is not installed)
If neither is installed, the service still runs; images come back with
ocr_status = 'failed' and OAS staff review them manually.
"""
import os
import tempfile
import threading

try:
    import pymupdf as fitz  # PyMuPDF (new name)
except ImportError:
    import fitz  # PyMuPDF (old name)

_ocr = None
_ocr_error = None


# PaddleOCR is not safe to use from two requests at the same time; every use goes through this lock.
# (The FastAPI endpoints run in worker threads so the service keeps answering other requests.)
_OCR_LOCK = threading.Lock()
_LOAD_LOCK = threading.Lock()

# Pages and pictures bigger than this (longest side, pixels) are made smaller before OCR.
# A phone photo of 4000 px takes several times longer to read and is not read better.
MAX_IMAGE_SIDE = int(os.getenv("OCR_MAX_IMAGE_SIDE", "2200"))


class _LockedOCR:
    """The PaddleOCR object, used by one request at a time."""

    def __init__(self, inner):
        self._inner = inner
        if hasattr(inner, "predict"):
            self.predict = self._predict

    def _predict(self, *args, **kwargs):
        with _OCR_LOCK:
            return self._inner.predict(*args, **kwargs)

    def ocr(self, *args, **kwargs):
        with _OCR_LOCK:
            return self._inner.ocr(*args, **kwargs)

    def __getattr__(self, name):
        return getattr(self._inner, name)


def _paddle_options():
    """
    PaddleOCR 3.x settings. Optional, in the AI service's environment:
      OCR_DET_MODEL / OCR_REC_MODEL   lighter models, e.g. PP-OCRv5_mobile_det and en_PP-OCRv5_mobile_rec
                                      (faster on a laptop CPU, a little less accurate; NOT TESTED on the OAS files)
      OCR_DET_LIMIT                   longest side (pixels) the text detector works at, e.g. 1600
                                      (not set = PaddleOCR's own setting; the TES list reader is tuned for that)
    """
    options = {}
    if os.getenv("OCR_DET_MODEL"):
        options["text_detection_model_name"] = os.getenv("OCR_DET_MODEL")
    if os.getenv("OCR_REC_MODEL"):
        options["text_recognition_model_name"] = os.getenv("OCR_REC_MODEL")
    if os.getenv("OCR_DET_LIMIT"):
        options["text_det_limit_side_len"] = int(os.getenv("OCR_DET_LIMIT"))
        options["text_det_limit_type"] = "max"
    return options


def get_ocr():
    """Load PaddleOCR once and reuse it (the service also loads it at start-up, so the first check is not slow)."""
    global _ocr, _ocr_error
    if _ocr is not None:
        return _ocr
    if _ocr_error is not None:
        return None
    with _LOAD_LOCK:
        if _ocr is not None:
            return _ocr
        try:
            # Paddle 3.x on Windows CPUs can stop with "ConvertPirAttribute2RuntimeAttribute not support"
            # when its oneDNN (MKL-DNN) speed-up is on. Turning it off fixes it; OCR is a little slower.
            os.environ.setdefault("FLAGS_use_mkldnn", "0")
            os.environ.setdefault("FLAGS_enable_pir_api", "0")
            from paddleocr import PaddleOCR
            base = dict(
                lang="en",
                use_doc_orientation_classify=False,
                use_doc_unwarping=False,
                use_textline_orientation=False,
                enable_mkldnn=False,
            )
            inner = None
            try:  # PaddleOCR 3.x (with the optional settings above)
                inner = PaddleOCR(**base, **_paddle_options())
            except (TypeError, ValueError):
                try:  # PaddleOCR 3.x without them (older 3.x)
                    inner = PaddleOCR(**base)
                except (TypeError, ValueError):  # PaddleOCR 2.x style
                    inner = PaddleOCR(lang="en", use_angle_cls=True)
            _ocr = _LockedOCR(inner)
            return _ocr
        except Exception as e:  # not installed, or failed to load
            _ocr_error = f"{type(e).__name__}: {e}"
            return None


def warm_up():
    """Called when the service starts: loads PaddleOCR in the background."""
    threading.Thread(target=get_ocr, name="ocr-warm-up", daemon=True).start()


def _smaller_copy(path):
    """A temporary smaller copy of a big picture (or None when it is small enough)."""
    try:
        from PIL import Image, ImageOps
        with Image.open(path) as image:
            if max(image.size) <= MAX_IMAGE_SIDE:
                return None
            image = ImageOps.exif_transpose(image).convert("RGB")
            image.thumbnail((MAX_IMAGE_SIDE, MAX_IMAGE_SIDE))
            fd, small = tempfile.mkstemp(suffix=".png")
            os.close(fd)
            image.save(small)
            return small
    except Exception:
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
    small = _smaller_copy(path)
    try:
        return _ocr_image_as_is(small or path)
    finally:
        if small:
            os.remove(small)


def _ocr_image_as_is(path: str):
    ocr = get_ocr()
    if ocr is None:
        if ocr_engine_name() == "tesseract":
            with _OCR_LOCK:
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


# A document for the AI check is a certificate of a page or two. Scanned pages after this number are
# not read (a 30-page scanned file would otherwise take many minutes); the result says so.
MAX_OCR_PAGES = int(os.getenv("OCR_MAX_PAGES", "4"))


def extract_text(path: str, filename: str = "") -> dict:
    """Returns {'text', 'engine', 'ocr_status', 'error'}."""
    ext = os.path.splitext(filename or path)[1].lower()

    try:
        if ext == ".pdf":
            doc = fitz.open(path)
            if getattr(doc, "needs_pass", False):
                return {"text": "", "engine": None, "ocr_status": "failed",
                        "error": "the PDF is password-protected; ask the student for a copy without a password"}
            parts, used_ocr, ocr_pages, skipped = [], False, 0, 0
            for page in doc:
                text = page.get_text().strip()
                if len(text) >= 30:
                    parts.append(text)
                elif ocr_pages >= MAX_OCR_PAGES:
                    skipped += 1
                else:  # scanned page -> render to PNG and OCR it
                    ocr_pages += 1
                    pix = page.get_pixmap(dpi=200)
                    with tempfile.NamedTemporaryFile(suffix=".png", delete=False) as tmp:
                        tmp_path = tmp.name
                    try:
                        pix.save(tmp_path)
                        parts.append(_ocr_image(tmp_path))
                        used_ocr = True
                    finally:
                        os.remove(tmp_path)
            note = (f"Only the first {MAX_OCR_PAGES} scanned pages were read; {skipped} more scanned "
                    f"page(s) were skipped. Upload only the pages of this document.") if skipped else None
            return {"text": "\n".join(parts), "engine": (ocr_engine_name() or "ocr") if used_ocr else "pdf-text",
                    "ocr_status": "ok", "error": None, "note": note, "pages": len(doc)}

        # images
        return {"text": _ocr_image(path), "engine": ocr_engine_name() or "ocr", "ocr_status": "ok", "error": None}

    except Exception as e:
        return {"text": "", "engine": None, "ocr_status": "failed", "error": f"{type(e).__name__}: {e}"}
