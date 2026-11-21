"""Each PDF page reports how it was read: its own text layer, or OCR (fictional data)."""
import fitz

from list_reader import table_rows


def _text_pdf(path):
    doc = fitz.open()
    page = doc.new_page(width=842, height=595)
    y = 60
    for line in ["Student Number   Last Name   First Name",
                 "2026-90001   CRUZ   ANA",
                 "2026-90002   REYES   BEN",
                 "2026-90003   SANTOS   CARLO"]:
        page.insert_text((50, y), line, fontsize=11)
        y += 20
    doc.save(path)


def test_a_text_pdf_is_read_from_its_text_layer_page_by_page(tmp_path):
    path = tmp_path / "approved-text.pdf"
    _text_pdf(str(path))

    result = table_rows(str(path), "approved-text.pdf")

    assert result["method"] == "pdf-text"
    methods = result["metadata"]["page_methods"]
    assert methods == [{"page": 1, "method": "text"}]
    assert result["metadata"]["text_pages"] == 1
    assert result["metadata"]["ocr_pages"] == 0
    assert any("2026-90002" in " ".join(row) for row in result["rows"])
