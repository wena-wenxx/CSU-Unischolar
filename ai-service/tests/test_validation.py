try:
    import pymupdf as fitz
except ImportError:
    import fitz
from fastapi.testclient import TestClient

from main import app
from validation import best_name_match, normalize, validate_text

client = TestClient(app)


def make_pdf(tmp_path, lines, name="doc.pdf"):
    p = tmp_path / name
    doc = fitz.open()
    page = doc.new_page()
    y = 72
    for line in lines:
        page.insert_text((72, y), line, fontsize=12)
        y += 20
    doc.save(str(p))
    doc.close()
    return p


# ---------- name matching ----------
def test_normalize():
    assert normalize("Contiga, Wena-Rose!") == "CONTIGA WENA ROSE"


def test_exact_and_reversed_names_match():
    assert best_name_match("Wena Contiga", "Name: CONTIGA, WENA")[0] >= 90
    assert best_name_match("Wena Contiga", "Name: WENA ROSE CONTIGA")[0] >= 90


def test_similar_name_goes_to_review_band():
    score, _ = best_name_match("Wena Contiga", "Name: WENA CONTIGO")
    assert 75 <= score < 100


def test_different_person_is_low():
    assert best_name_match("Wena Contiga", "Name: REYES, ANA")[0] < 75


# ---------- rules ----------
GOOD = "CERTIFICATE OF REGISTRATION Name: SANTOS, MARIA Student ID: 2026-00100 Semester 1st Subjects units enrolled"


def test_correct_document_has_no_flags():
    r = validate_text(GOOD, "Maria Santos", "2026-00100", "Certificate of Registration")
    assert r["flags"] == [] and r["is_complete"] and not r["has_name_mismatch"]


def test_wrong_person_flagged():
    r = validate_text(GOOD.replace("SANTOS, MARIA", "REYES, ANA"), "Maria Santos", "2026-00100", "Certificate of Registration")
    assert r["has_name_mismatch"]


def test_wrong_document_flagged():
    text = "BARANGAY CLEARANCE certify MARIA SANTOS resident of Barangay Ampayon Butuan City"
    r = validate_text(text, "Maria Santos", "2026-00100", "Grades (Report Card)")
    assert r["has_wrong_document"] and not r["is_complete"]


def test_missing_student_id_flagged():
    r = validate_text(GOOD.replace("2026-00100", ""), "Maria Santos", "2026-00100", "Certificate of Registration")
    assert r["has_missing_information"]


def test_blank_document_flagged():
    r = validate_text(".", "Maria Santos", "2026-00100", "Valid ID")
    assert r["has_missing_information"] and not r["is_complete"]


def test_unknown_label_does_not_guess_wrong_document():
    r = validate_text(GOOD, "Maria Santos", "2026-00100", "Barangay Certificate")
    assert not r["has_wrong_document"]


# ---------- the real HTTP endpoint ----------
def test_endpoint_correct_pdf(tmp_path):
    pdf = make_pdf(tmp_path, ["CERTIFICATE OF REGISTRATION", "Name: SANTOS, MARIA",
                              "Student ID: 2026-00100", "Semester 1st  Subjects  Total units 24"])
    with open(pdf, "rb") as f:
        r = client.post("/validate-document", files={"file": ("cor.pdf", f, "application/pdf")},
                        data={"expected_name": "Maria Santos", "expected_student_id": "2026-00100",
                              "document_label": "Certificate of Registration"})
    body = r.json()
    assert r.status_code == 200 and body["ocr_status"] == "ok"
    assert body["flags"] == [] and body["is_complete"] is True


def test_endpoint_wrong_person_pdf(tmp_path):
    pdf = make_pdf(tmp_path, ["CERTIFICATE OF REGISTRATION", "Name: REYES, ANA",
                              "Student ID: 2026-00102", "Semester 1st  Subjects  Total units 24"])
    with open(pdf, "rb") as f:
        r = client.post("/validate-document", files={"file": ("cor.pdf", f, "application/pdf")},
                        data={"expected_name": "Maria Santos", "expected_student_id": "2026-00100",
                              "document_label": "Certificate of Registration"})
    assert r.json()["has_name_mismatch"] is True


def test_endpoint_image_without_paddle_fails_gracefully(tmp_path):
    img = tmp_path / "scan.png"
    img.write_bytes(b"\x89PNG\r\n\x1a\n" + b"0" * 50)
    with open(img, "rb") as f:
        r = client.post("/validate-document", files={"file": ("scan.png", f, "image/png")},
                        data={"expected_name": "Maria Santos"})
    body = r.json()
    assert r.status_code == 200
    assert body["ocr_status"] == "failed" and body["flags"]


def test_ocr_status_endpoint():
    assert client.get("/ocr-status").status_code == 200


def test_one_letter_surname_difference_goes_to_review():
    text = "CERTIFICATE OF REGISTRATION Name: WENA CONTIGO Student ID: 2026-00100 Semester Subjects units"
    r = validate_text(text, "Wena Contiga", "2026-00100", "Certificate of Registration")
    assert r["has_name_mismatch"] is True
    assert any("similar" in f.lower() for f in r["flags"])


def test_middle_name_on_document_is_accepted():
    text = "CERTIFICATE OF REGISTRATION Name: WENA ROSE CONTIGA Student ID: 2026-00100 Semester Subjects units"
    r = validate_text(text, "Wena Contiga", "2026-00100", "Certificate of Registration")
    assert r["has_name_mismatch"] is False
