"""
Makes FAKE sample documents (PDF) to test the AI validation.
Run:  python make_sample_docs.py
Then upload the PDFs from the 'sample_docs' folder in the student portal.
Uses only synthetic names - no real student data.
"""
import os
try:
    import pymupdf as fitz
except ImportError:
    import fitz

os.makedirs("sample_docs", exist_ok=True)


def make(filename, lines):
    doc = fitz.open()
    page = doc.new_page()
    y = 72
    for line in lines:
        page.insert_text((72, y), line, fontsize=12)
        y += 20
    doc.save(os.path.join("sample_docs", filename))
    doc.close()


# Change these two to match the student you registered / seeded
NAME = "Maria Santos"
SID = "2026-00100"

make("1_registration_CORRECT.pdf", [
    "CARAGA STATE UNIVERSITY", "CERTIFICATE OF REGISTRATION",
    f"Name: SANTOS, MARIA", f"Student ID: {SID}", "Program: BSIT",
    "Semester: 1st Semester 2026-2027", "Subjects enrolled: 8   Total units: 24",
])
make("2_registration_NAME_TYPO.pdf", [
    "CARAGA STATE UNIVERSITY", "CERTIFICATE OF REGISTRATION",
    "Name: SANTOZ, MARYA", f"Student ID: {SID}", "Program: BSIT",
    "Semester: 1st Semester 2026-2027", "Subjects enrolled: 8   Total units: 24",
])
make("3_registration_WRONG_PERSON.pdf", [
    "CARAGA STATE UNIVERSITY", "CERTIFICATE OF REGISTRATION",
    "Name: REYES, ANA", "Student ID: 2026-00102", "Program: BSCS",
    "Semester: 1st Semester 2026-2027", "Subjects enrolled: 8   Total units: 24",
])
make("4_grades_CORRECT.pdf", [
    "CARAGA STATE UNIVERSITY", "REPORT OF GRADES",
    f"Student: MARIA SANTOS", f"Student ID: {SID}",
    "Semester: 1st Semester 2025-2026", "General Weighted Average (GWA): 1.50",
    "Subject grades listed below (units and grades)...",
])
make("5_WRONG_DOCUMENT.pdf", [
    "BARANGAY CLEARANCE", "This is to certify that MARIA SANTOS is a resident of",
    "Barangay Ampayon, Butuan City and has no pending case.",
    "Issued for employment purposes only.",
])
make("6_nearly_BLANK.pdf", ["."])
print("Done! Files are in the 'sample_docs' folder.")
