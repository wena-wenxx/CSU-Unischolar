import os
import tempfile
import threading

try:
    import pymupdf as fitz
except ImportError:
    import fitz

_ocr = None
_ocr_error = None

_OCR_LOCK = threading.Lock()
_LOAD_LOCK = threading.Lock()

MAX_IMAGE_SIDE = int(os.getenv("OCR_MAX_IMAGE_SIDE", "2200"))


class _LockedOCR:
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
    global _ocr, _ocr_error
    if _ocr is not None:
        return _ocr
    if _ocr_error is not None:
        return None
    with _LOAD_LOCK:
        if _ocr is not None:
            return _ocr
        try:
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
            try:
                inner = PaddleOCR(**base, **_paddle_options())
            except (TypeError, ValueError):
                try:
                    inner = PaddleOCR(**base)
                except (TypeError, ValueError):
                    inner = PaddleOCR(lang="en", use_angle_cls=True)
            _ocr = _LockedOCR(inner)
            return _ocr
        except Exception as e:
            _ocr_error = f"{type(e).__name__}: {e}"
            return None


def warm_up():
    threading.Thread(target=get_ocr, name="ocr-warm-up", daemon=True).start()


def _smaller_copy(path):
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
    texts = []
    for page in result or []:
        if page is None:
            continue
        try:
            texts.extend(list(page["rec_texts"]))
            continue
        except Exception:
            pass
        try:
            texts.extend(list(page.json["res"]["rec_texts"]))
            continue
        except Exception:
            pass
        if isinstance(page, list):
            for line in page:
                try:
                    texts.append(line[1][0])
                except Exception:
                    pass
    return texts


def _tesseract_cmd():
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
    pytesseract = _pytesseract()
    from PIL import Image, ImageOps

    image = ImageOps.exif_transpose(Image.open(path)).convert("L")
    if max(image.size) < 1800:
        scale = 1800 / max(image.size)
        image = image.resize((int(image.width * scale), int(image.height * scale)))
    text = pytesseract.image_to_string(image, config="--psm 3")

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

MAX_OCR_PAGES = int(os.getenv("OCR_MAX_PAGES", "4"))


def extract_text(path: str, filename: str = "") -> dict:
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
                else:
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

        return {"text": _ocr_image(path), "engine": ocr_engine_name() or "ocr", "ocr_status": "ok", "error": None}

    except Exception as e:
        return {"text": "", "engine": None, "ocr_status": "failed", "error": f"{type(e).__name__}: {e}"}
