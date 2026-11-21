"""
Scanned TES billing file (ruled table). Uses a FICTIONAL file with the same layout as the CHED form.
The word positions are given directly, so these tests do not need PaddleOCR or Tesseract.
"""
import os
import sys

import pytest

cv2 = pytest.importorskip("cv2")
pytest.importorskip("PIL")

sys.path.insert(0, os.path.join(os.path.dirname(__file__), "..", "samples"))

import ruled_table as R  # noqa: E402
import tes_billing_sample as T  # noqa: E402


def _read(tmp_path, rows, drop_pages=(), **kwargs):
    pages = T.make_pages(rows, **kwargs)
    paths, words = [], {}
    for i, (img, page_words) in enumerate(pages):
        if i in drop_pages:
            continue
        path = str(tmp_path / f"page{i}.png")
        img.save(path)
        paths.append(path)
        words[path] = page_words
    return R.read_document(paths, page_words_fn=lambda p: words[p])


def test_every_row_is_read_with_its_wrapped_values_joined(tmp_path):
    rows = T.fictional_rows(30)
    result = _read(tmp_path, rows, tilt=0.4)

    assert len(result["rows"]) == 30
    for got in result["rows"]:
        expected = rows[int(got[0]) - 1]
        assert got == [expected[f] for f in R.TES_COLUMNS]
    assert result["warnings"] == []


def test_a_row_that_continues_on_the_next_page_gets_its_full_award_number(tmp_path):
    rows = T.fictional_rows(30)
    result = _read(tmp_path, rows)          # rows 12 and 24 are cut at the bottom of their page
    by_control = {r[0]: r for r in result["rows"]}
    assert by_control["00012"][2] == rows[11]["award_number"]
    assert by_control["00024"][2] == rows[23]["award_number"]
    assert [p["continued_row"] for p in result["pages"]] == [False, True, True]


def test_titles_totals_and_signatures_are_not_student_rows(tmp_path):
    result = _read(tmp_path, T.fictional_rows(15))
    joined = " ".join(" ".join(r) for r in result["rows"]).lower()
    for word in ("total", "prepared", "certified", "campus name", "consolidated"):
        assert word not in joined
    meta = result["metadata"]
    assert meta["batch"] == "2" and meta["semester"] == "1st Semester"
    assert meta["printed_total"] == 150000.0 and meta["expected_count"] == 15


def test_format_fixes_are_written_down_and_names_are_never_changed(tmp_path):
    rows = T.fictional_rows(15)
    mistakes = {
        ("00005", "control_number"): "0O005",
        ("00006", "amount"): "10,0O0.00",
        ("00007", "sex"): "EMALE",
        ("00008", "last"): "0CAMP0",          # a name with digits: flagged, not "corrected"
    }
    result = _read(tmp_path, rows, mistakes=mistakes)
    by_control = {r[0]: (r, c) for r, c in zip(result["rows"], result["cells"])}

    row, cells = by_control["00005"]
    assert row[0] == "00005" and "0O005" in cells[0]["note"]
    row, cells = by_control["00006"]
    assert row[13] == "10,000.00" and cells[13]["note"]
    row, cells = by_control["00007"]
    assert row[6] == "FEMALE" and cells[6]["check"]        # a letter was missing: staff must look
    row, cells = by_control["00008"]
    assert row[3] == "0CAMP0" and cells[3]["check"]


def test_a_missing_page_is_reported_with_the_missing_control_numbers(tmp_path):
    result = _read(tmp_path, T.fictional_rows(30), drop_pages=(1,))
    text = " ".join(result["warnings"])
    assert "Control numbers are missing: 00013–00024" in text
    assert "= 30 grantees, but" in text
    # row 12 was cut at the bottom of page 1 and its end was on the missing page: flagged, not mixed up
    row12 = next((r, c) for r, c in zip(result["rows"], result["cells"]) if r[0] == "00012")
    assert row12[1][2]["check"]
    assert "Page 2 starts with the end of a row whose beginning is not in the file" in text


def test_clean_field_rules():
    assert R.clean_field("award_number", "lES-20240808- 172600000000-027269")[0] == "TES-20240808-172600000000-027269"
    assert R.clean_field("student_id", "231- 01872")[0] == "231-01872"
    assert R.clean_field("student_id", "23l-0l872")[0] == "231-01872"
    assert R.clean_field("student_id", "ABCD")[1] < R.LOW
    assert R.clean_field("amount", "10.000,00")[0] == "10,000.00"
    assert R.clean_field("pwd", "N0")[0] == "No"
    assert R.clean_field("phone", "0993 983 8295")[0] == "09939838295"
    assert R.clean_field("last", "dela cruz")[0] == "DELA CRUZ"          # only upper-cased
    value, conf, _ = R.clean_field("sex", "EMALE")
    assert value == "FEMALE" and conf < R.LOW


def test_a_digit_that_differs_from_every_other_row_is_highlighted():
    rows = [[f"{n:05d}", f"221-0{1000 + n}", f"TES-20240808-172600000000-0{27000 + n}"] + [""] * 13
            for n in range(1, 21)]
    rows[4][1] = "221-91005"            # OCR read 0 as 9
    cells = [[{"conf": 0.95, "note": "", "check": False} for _ in range(16)] for _ in rows]
    R._use_whole_file(rows, cells, {}, R.TES_COLUMNS)
    assert cells[4][1]["check"] and "almost every other row" in cells[4][1]["note"]
    assert not any(c[1]["check"] for i, c in enumerate(cells) if i != 4)


def test_any_scanned_list_with_table_lines_is_read_cell_by_cell(tmp_path):
    """A fictional CMSP list (not a known CHED form), slightly tilted and blurred. Needs an OCR program."""
    if not R.ocr_engines_available():
        pytest.skip("no OCR program installed (PaddleOCR or Tesseract)")
    from PIL import Image, ImageDraw, ImageFilter

    font = T._font(26)
    rows = [("No.", "Name of Scholar", "Student No.", "Course"), ("1", "NAVARRO, JUAN L.", "2026-00001", "BSIT"),
            ("2", "SANTOS, MARIA C.", "2026-00002", "BSIT"), ("3", "RAMIREZ, ANA T.", "2026-00003", "BSCS")]
    img = Image.new("L", (1700, 600), 250)
    d = ImageDraw.Draw(img)
    xs = [80, 200, 700, 1050, 1400]
    for r, row in enumerate(rows):
        y = 100 + r * 80
        d.line([(80, y), (1400, y)], fill=30, width=3)
        for c, value in enumerate(row):
            d.text((xs[c] + 15, y + 22), value, font=font, fill=20)
    d.line([(80, 100 + len(rows) * 80), (1400, 100 + len(rows) * 80)], fill=30, width=3)
    for x in xs:
        d.line([(x, 100), (x, 100 + len(rows) * 80)], fill=30, width=3)
    path = str(tmp_path / "cmsp.png")
    img.rotate(0.6, fillcolor=250).filter(ImageFilter.GaussianBlur(0.6)).save(path)

    doc = R.read_generic(path)
    assert doc and len(doc["rows"]) == 4
    assert [r[2] for r in doc["rows"][1:]] == ["2026-00001", "2026-00002", "2026-00003"]
