"""
Tests for authenticity.py ("possible edit" cautions). Run: python -m pytest
"""
from authenticity import image_notes, pdf_notes


def test_normal_scanner_pdf_has_no_caution():
    meta = {"creator": "Canon ScanGear", "producer": "Canon", "creationDate": "D:20260901093000+08'00'",
            "modDate": "D:20260901093000+08'00'"}
    assert pdf_notes(meta, b"%PDF-1.4 ... %%EOF") == []


def test_word_made_certificate_is_not_flagged():
    meta = {"creator": "Microsoft Word for Microsoft 365", "producer": "Microsoft Word", "creationDate": "", "modDate": ""}
    assert pdf_notes(meta, b"%%EOF") == []


def test_editing_program_is_a_caution_not_a_verdict():
    notes = pdf_notes({"producer": "Canva", "creator": ""}, b"%%EOF")
    assert len(notes) == 1 and "Canva" in notes[0] and "not proof" in notes[0]


def test_changed_after_creation_and_saved_again():
    meta = {"creationDate": "D:20260101080000", "modDate": "D:20260315170000"}
    notes = pdf_notes(meta, b"%PDF ... %%EOF ... %%EOF ... %%EOF")
    assert any("changed after" in n for n in notes)
    assert any("saved again 2 time" in n for n in notes)


def test_linearized_pdf_with_two_markers_is_normal():
    raw = b"%PDF-1.5 1 0 obj <</Linearized 1>> ... %%EOF ... %%EOF"
    assert pdf_notes({}, raw) == []


def test_picture_saved_with_photoshop(tmp_path):
    from PIL import Image, PngImagePlugin
    info = PngImagePlugin.PngInfo()
    info.add_text("Software", "Adobe Photoshop 25.0")
    path = tmp_path / "id.png"
    Image.new("RGB", (20, 20), "white").save(path, pnginfo=info)
    assert "Photoshop" in image_notes(str(path))[0]

    plain = tmp_path / "scan.png"
    Image.new("RGB", (20, 20), "white").save(plain)
    assert image_notes(str(plain)) == []
