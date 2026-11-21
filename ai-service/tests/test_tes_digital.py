"""A TES billing list saved as a clear PDF (real text and drawn table lines) is read without OCR (fictional)."""
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent.parent / "samples"))

import tes_billing_sample as tes  # noqa: E402
from make_test_files import pdf_from_pages, row  # noqa: E402
from list_reader import table_rows  # noqa: E402


def _rows():
    return [row(1, "211-01234", "ABAD", "ANA", "REYES", "FEMALE", "BACHELOR OF ARTS IN SOCIOLOGY", "1"),
            row(2, "2026-00001", "NAVARRO", "JUAN", "LOPEZ", "MALE", "BACHELOR OF SCIENCE IN INFORMATION TECHNOLOGY", "4"),
            row(3, "221-05678", "DELA PEÑA", "ROSA", "CRUZ", "FEMALE", "BACHELOR OF SCIENCE IN BIOLOGY", "2")]


def test_a_digital_tes_billing_pdf_is_read_from_its_text_and_lines(tmp_path):
    pages = tes.make_pages(_rows(), split_last_row=False, school_year="2026-2027")
    path = tmp_path / "tes-digital.pdf"
    pdf_from_pages(path, pages, digital=True)

    result = table_rows(str(path), "tes-digital.pdf")

    assert result["method"] == "pdf-text"
    data = result["rows"][1:]
    assert [r[1] for r in data] == ["211-01234", "2026-00001", "221-05678"]
    assert data[2][3] == "DELA PEÑA"
    assert data[1][2] == "TES-20990101-990000000000-900002"
    assert result["metadata"]["batch"] == "Batch 2"
    assert result["metadata"]["summed_amount"] == result["metadata"]["printed_total"] == 30000.0
    assert result["metadata"]["ocr_pages"] == 0
