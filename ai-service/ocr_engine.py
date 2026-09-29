"""
Text extraction.
- PDF with real text  -> read the text directly (fast, no OCR needed)
- Scanned PDF / image -> PaddleOCR
If PaddleOCR is not installed, the service still runs; images just come
back with ocr_status = 'failed' and OAS staff review them manually.
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


def _ocr_image(path: str):
    ocr = get_ocr()
    if ocr is None:
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
            return {"text": "\n".join(parts), "engine": "paddleocr" if used_ocr else "pdf-text",
                    "ocr_status": "ok", "error": None}

        # images
        return {"text": _ocr_image(path), "engine": "paddleocr", "ocr_status": "ok", "error": None}

    except Exception as e:
        return {"text": "", "engine": None, "ocr_status": "failed", "error": f"{type(e).__name__}: {e}"}
