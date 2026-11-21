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
    assert normalize("Villanueva, Liza-Mae!") == "VILLANUEVA LIZA MAE"


def test_exact_and_reversed_names_match():
    assert best_name_match("Liza Villanueva", "Name: VILLANUEVA, LIZA")[0] >= 90
    assert best_name_match("Liza Villanueva", "Name: LIZA MAE VILLANUEVA")[0] >= 90


def test_similar_name_goes_to_review_band():
    score, _ = best_name_match("Liza Villanueva", "Name: LIZA VILLANUEVO")
    assert 75 <= score < 100


def test_different_person_is_low():
    assert best_name_match("Liza Villanueva", "Name: REYES, ANA")[0] < 75


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
    text = "CERTIFICATE OF REGISTRATION Name: LIZA VILLANUEVO Student ID: 2026-00100 Semester Subjects units"
    r = validate_text(text, "Liza Villanueva", "2026-00100", "Certificate of Registration")
    assert r["has_name_mismatch"] is True
    assert any("similar" in f.lower() for f in r["flags"])


def test_middle_name_on_document_is_accepted():
    text = "CERTIFICATE OF REGISTRATION Name: LIZA MAE VILLANUEVA Student ID: 2026-00100 Semester Subjects units"
    r = validate_text(text, "Liza Villanueva", "2026-00100", "Certificate of Registration")
    assert r["has_name_mismatch"] is False


# ---------- new document kinds (birth certificate, indigency, barangay clearance) ----------
BIRTH = "Republic of the Philippines PSA Office of the Civil Registrar General CERTIFICATE OF LIVE BIRTH Child: MARIA SANTOS"
INDIGENCY = "Republic of the Philippines Barangay Ampayon CERTIFICATE OF INDIGENCY This certifies that MARIA SANTOS is an indigent resident"
CLEARANCE = "Republic of the Philippines Barangay Ampayon BARANGAY CLEARANCE This certifies that MARIA SANTOS is a resident of good standing"


def test_correct_birth_certificate_has_no_flags():
    r = validate_text(BIRTH, "Maria Santos", "2026-00100", "Birth Certificate")
    assert r["flags"] == [] and r["is_complete"]
    assert r["extracted_data"]["document_kind"] == "birth_certificate"


def test_birth_certificate_structured_fields_include_authority_and_date():
    text = (
        "Philippine Statistics Authority PSA "
        "Office of the Civil Registrar General "
        "CERTIFICATE OF LIVE BIRTH Child: MARIA SANTOS Date: November 3, 2004"
    )
    r = validate_text(text, "Maria Santos", "2026-00100", "Birth Certificate")
    fields = r["extracted_data"]
    assert "Philippine Statistics Authority" in fields["issuing_authorities"]
    assert "November 3, 2004" in fields["dates"]
    assert fields["document_date"] == "November 3, 2004"


def test_itr_structured_fields_preserve_extracted_income_for_review():
    text = (
        "BUREAU OF INTERNAL REVENUE Annual Income Tax Return "
        "Taxpayer: MARIA SANTOS Taxable Income PHP 120,000.00"
    )
    r = validate_text(text, "Maria Santos", "2026-00100", "Parents' Income Tax Return",
                      expected_last_name="Santos")
    assert r["extracted_data"]["extracted_family_income"] == "120000.00"


def test_birth_certificate_does_not_need_student_id():
    r = validate_text(BIRTH, "Maria Santos", "2026-00100", "Birth Certificate")
    assert not r["has_missing_information"]


def test_cor_uploaded_as_birth_certificate_is_flagged():
    r = validate_text(GOOD, "Maria Santos", "2026-00100", "Birth Certificate")
    assert r["has_wrong_document"]


def test_correct_indigency_has_no_flags():
    r = validate_text(INDIGENCY, "Maria Santos", "2026-00100", "Certificate of Indigency")
    assert r["flags"] == [] and r["extracted_data"]["document_kind"] == "indigency"


def test_clearance_uploaded_as_indigency_is_flagged():
    r = validate_text(CLEARANCE, "Maria Santos", "2026-00100", "Certificate of Indigency")
    assert r["has_wrong_document"]


def test_correct_barangay_clearance_has_no_flags():
    r = validate_text(CLEARANCE, "Maria Santos", "2026-00100", "Barangay Clearance")
    assert r["flags"] == [] and r["extracted_data"]["document_kind"] == "barangay_clearance"


def test_indigency_uploaded_as_clearance_is_flagged():
    r = validate_text(INDIGENCY, "Maria Santos", "2026-00100", "Barangay Clearance")
    assert r["has_wrong_document"]


# ---------- good moral, parents' ITR, recommendation letter ----------
GOOD_MORAL = "CERTIFICATE OF GOOD MORAL CHARACTER This certifies that MARIA SANTOS has shown good moral character while enrolled"
ITR = "BUREAU OF INTERNAL REVENUE Annual Income Tax Return Taxpayer: ROBERTO SANTOS Taxable Income 120,000.00 Tax Due 0.00"
RECOMMENDATION = "To the Scholarship Committee: I am pleased to recommend MARIA SANTOS for your scholarship program."


def test_good_moral_is_recognised():
    r = validate_text(GOOD_MORAL, "Maria Santos", "2026-00100", "Certificate of Good Moral Character")
    assert r["flags"] == [] and r["extracted_data"]["document_kind"] == "good_moral"


def test_parents_itr_only_needs_the_surname():
    r = validate_text(ITR, "Maria Santos", "2026-00100", "Parents' Income Tax Return", expected_last_name="Santos")
    assert r["flags"] == [] and r["extracted_data"]["name_checked"] == "surname"


def test_parents_itr_of_another_family_is_flagged():
    r = validate_text(ITR.replace("SANTOS", "REYES"), "Maria Santos", "2026-00100", "Parents' Income Tax Return", expected_last_name="Santos")
    assert r["has_name_mismatch"]


def test_birth_certificate_uploaded_as_itr_is_flagged():
    birth = "PSA Office of the Civil Registrar General CERTIFICATE OF LIVE BIRTH Child: MARIA SANTOS"
    r = validate_text(birth, "Maria Santos", "2026-00100", "Parents' Income Tax Return", expected_last_name="Santos")
    assert r["has_wrong_document"]


def test_recommendation_letter_is_recognised():
    r = validate_text(RECOMMENDATION, "Maria Santos", "2026-00100", "Recommendation Letter")
    assert r["flags"] == [] and r["extracted_data"]["document_kind"] == "recommendation_letter"


def test_recommendation_uploaded_as_good_moral_is_flagged():
    r = validate_text(RECOMMENDATION, "Maria Santos", "2026-00100", "Certificate of Good Moral Character")
    assert r["has_wrong_document"]


# ---- school year of a COR, birth certificate parents, password (added after the TES review) ----

COR_TEXT = ("CARAGA STATE UNIVERSITY CERTIFICATE OF REGISTRATION Student No. 2026-00101 "
            "Name: SANTOS, MARIA C. First Semester AY {year} Subject Units Total Units 21")


def test_school_years_are_read_in_their_usual_forms():
    from validation import school_years_in
    assert school_years_in("AY 2024 - 2025") == ["2024-2025"]
    assert school_years_in("SY 2025-26 and 2026-2027") == ["2026-2027", "2025-2026"]
    assert school_years_in("born 2004-2005?") == ["2004-2005"]
    assert school_years_in("2026-00101") == []          # a Student ID is not a school year


def test_a_cor_from_an_old_school_year_is_flagged():
    r = validate_text(COR_TEXT.format(year="2024-2025"), "Maria Santos", "2026-00101",
                      "Certificate of Registration (COR)", current_school_year="2026-2027")
    assert r["has_missing_information"]
    assert any("school year 2024-2025, not the current 2026-2027" in f for f in r["flags"])


def test_a_cor_for_the_current_school_year_passes():
    r = validate_text(COR_TEXT.format(year="2026-2027"), "Maria Santos", "2026-00101",
                      "Certificate of Registration (COR)", current_school_year="2026-2027")
    assert r["is_complete"] and not r["flags"]


def test_a_birth_certificate_of_a_sibling_does_not_pass_on_the_parents_names():
    # The student is MARIA SANTOS; this is her brother's certificate, and their mother is also called
    # MARIA SANTOS. A name found only in the parents' part must not count as the student's.
    text = ("Republic of the Philippines PHILIPPINE STATISTICS AUTHORITY CERTIFICATE OF LIVE BIRTH "
            "1. NAME (First) PEDRO (Middle) CRUZ (Last) SANTOS 2. SEX MALE 3. DATE OF BIRTH March 3, 2006 "
            "MOTHER 6. MAIDEN NAME (First) MARIA (Middle) LOPEZ (Last) SANTOS "
            "FATHER 13. NAME (First) JOSE (Middle) REYES (Last) SANTOS")
    r = validate_text(text, "Maria Santos", "2026-00101", "Birth Certificate")
    assert r["has_name_mismatch"]
    assert any("parents' part" in f for f in r["flags"])


def test_the_students_own_birth_certificate_still_passes():
    text = ("PHILIPPINE STATISTICS AUTHORITY CERTIFICATE OF LIVE BIRTH 1. NAME (First) MARIA (Middle) CRUZ "
            "(Last) SANTOS 2. SEX FEMALE MOTHER 6. MAIDEN NAME (First) ANA (Middle) LOPEZ (Last) CRUZ "
            "FATHER 13. NAME (First) JOSE (Middle) REYES (Last) SANTOS")
    r = validate_text(text, "Maria Santos", "2026-00101", "Birth Certificate")
    assert not r["has_name_mismatch"] and r["is_complete"]


def test_names_close_and_far():
    cor = COR_TEXT.format(year="2026-2027")
    same_surname = validate_text(cor.replace("SANTOS, MARIA", "SANTOS, PEDRO"), "Maria Santos", "2026-00101",
                                 "Certificate of Registration (COR)")
    other_surname = validate_text(cor.replace("SANTOS, MARIA", "REYES, MARIA"), "Maria Santos", "2026-00101",
                                  "Certificate of Registration (COR)")
    one_letter = validate_text(cor.replace("SANTOS, MARIA", "SANTOS, MARlA"), "Maria Santos", "2026-00101",
                               "Certificate of Registration (COR)")
    assert same_surname["has_name_mismatch"]          # a sibling's COR
    assert other_surname["has_name_mismatch"]
    # one wrong letter (an OCR slip or a typo) is not "not found": it goes to the "similar, please check"
    # band with its score, so staff look at it
    assert one_letter["has_name_mismatch"]
    assert any("similar but not exact" in f for f in one_letter["flags"])
    assert 85 <= one_letter["confidence_score"] < 95


# ---- parent documents that name the child (fictional people) ----------------
BIR_NO_ITR = (
    "BUREAU OF INTERNAL REVENUE Revenue District No. 999 CERTIFICATION THIS IS TO CERTIFY that no Income Tax "
    "Return has been filed by Mr./Mrs. PEDRO L. NAVARRO and his/her spouse ROSA M. NAVARRO resident/s of "
    "PUROK-1 SAMPLE CITY. This certification is issued upon the request of the above mentioned individual for "
    "SCHOLARSHIP purposes of his/her son/daughter/guardian JUAN L. NAVARRO. Done this 7th day of July, 2026."
)


def test_parent_document_for_the_applicant_passes():
    r = validate_text(BIR_NO_ITR, "Juan Navarro", "", "Parents' Income Tax Return (ITR)", "Navarro")
    assert r["extracted_data"]["review_status"] == "no_obvious_issues"
    assert "7th day of July, 2026" in r["extracted_data"]["dates"]


def test_parent_document_issued_for_a_sibling_is_flagged():
    r = validate_text(BIR_NO_ITR, "Maria Navarro", "", "Parents' Income Tax Return (ITR)", "Navarro")
    assert r["has_name_mismatch"]
    assert "issued for 'JUAN L NAVARRO'" in " ".join(r["flags"])


def test_income_written_as_income_of_is_read():
    text = ("OFFICE OF THE PUNONG BARANGAY CERTIFICATION This certifies that the parents of JUAN L. NAVARRO are "
            "identified indigent members with an income of P40,000.00 per annum.")
    r = validate_text(text, "Juan Navarro", "", "Certificate of Indigency", "Navarro")
    assert r["extracted_data"]["extracted_family_income"] == "40000.00"
    assert r["extracted_data"]["review_status"] == "no_obvious_issues"
