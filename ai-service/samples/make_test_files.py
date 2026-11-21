"""
Makes the TEST FILES for trying the whole OAS workflow on this computer.

    cd ai-service
    python samples/make_test_files.py

It reads the test student's name and Student ID from backend/.env (TEST_STUDENT_*), so the lists can
contain her (the real test student, used with her permission). Everybody else is FICTIONAL: the named
demo students made by the seeders (2026-00001 Juan Navarro, 2026-00002 Maria Santos, 2026-00003 Ana
Ramirez, 2026-00004 Pedro Garcia) and made-up people. Every page says it is a test file.

Output (inside private-test-data/, which is NOT uploaded to GitHub):
    private-test-data/generated/lists/
        01-tes-billing-GOOD-scanned.pdf      scanned-looking TES billing list (needs OCR)
        02-tes-billing-GOOD-digital.pdf      the same list as a clear PDF with real text (no OCR needed)
        03-tes-billing-PROBLEMS-scanned.pdf  unknown Student ID, wrong name, duplicate row, wrong total
        04-approved-list-GOOD.xlsx           approved list from an agency (Excel)
        05-approved-list-PROBLEMS.xlsx       the same problems in Excel, plus a row with no Student ID
        06-registrar-enrollment-list.xlsx    the Registrar's enrolled students (one is NOT on it)
    private-test-data/generated/problem-documents/
        blank-unreadable-scan.pdf            nothing to read -> "very little text" / manual review
        password-protected.pdf               password "test123" -> clear message
        broken-not-a-real-pdf.pdf            not really a PDF -> "could not be read"
        library-card-wrong-document.png      a picture of the wrong kind of document
        too-big-over-10MB.pdf                over the 10 MB upload limit -> refused
"""
import os
import random
import sys
from pathlib import Path

HERE = Path(__file__).resolve().parent
sys.path.insert(0, str(HERE.parent))
sys.path.insert(0, str(HERE))

import fitz  # PyMuPDF
from PIL import Image, ImageDraw, ImageFilter

import tes_billing_sample as tes

ROOT = HERE.parent.parent
OUT = ROOT / "private-test-data" / "generated"
SCHOOL_YEAR = "2026-2027"


def env_values():
    values = {}
    path = ROOT / "backend" / ".env"
    if path.exists():
        for line in path.read_text(encoding="utf-8", errors="ignore").splitlines():
            if "=" in line and not line.strip().startswith("#"):
                key, value = line.split("=", 1)
                values[key.strip()] = value.strip().strip('"')
    return values


ENV = env_values()
TEST = {
    "student_id": ENV.get("TEST_STUDENT_ID") or "000-00000",
    "first": (ENV.get("TEST_STUDENT_FIRST_NAME") or "Gleslimie").upper(),
    "middle": (ENV.get("TEST_STUDENT_MIDDLE_NAME") or "B.").upper().replace(".", ""),
    "last": (ENV.get("TEST_STUDENT_LAST_NAME") or "Binag").upper(),
    "sex": (ENV.get("TEST_STUDENT_SEX") or "Female").upper(),
    "program": (ENV.get("TEST_STUDENT_COURSE") or "Bachelor of Science in Information Technology").upper(),
}

# The named demo students of the seeders (fictional).
DEMO = [
    ("2026-00001", "NAVARRO", "JUAN", "LOPEZ", "MALE", "BACHELOR OF SCIENCE IN INFORMATION TECHNOLOGY", "4"),
    ("2026-00002", "SANTOS", "MARIA", "CRUZ", "FEMALE", "BACHELOR OF SCIENCE IN INFORMATION TECHNOLOGY", "3"),
    ("2026-00003", "RAMIREZ", "ANA", "TORRES", "FEMALE", "BACHELOR OF SCIENCE IN COMPUTER SCIENCE", "2"),
    ("2026-00004", "GARCIA", "PEDRO", "AQUINO", "MALE", "BACHELOR OF SCIENCE IN CIVIL ENGINEERING", "3"),
]


def row(n, student_id, last, first, middle, sex, program, year, amount="10,000.00"):
    return {
        "control_number": f"{n:05d}", "student_id": student_id,
        "award_number": f"TES-20990101-990000000000-{900000 + n:06d}",
        "last": last, "first": first, "middle": middle, "sex": sex, "birthdate": "",
        "program": program, "year_level": year, "email": f"test{n:03d}@example.com", "phone": f"0900{n:07d}",
        "batch": "2", "amount": amount, "pwd": "No", "total_amount": amount,
    }


def good_rows():
    rows = [row(1, TEST["student_id"], TEST["last"], TEST["first"], TEST["middle"], TEST["sex"], TEST["program"], "4")]
    for k, demo in enumerate(DEMO, start=2):
        rows.append(row(k, *demo))
    return rows


def problem_rows():
    rows = good_rows()[:3]                                    # test student, Juan, Maria: fine
    rows.append(row(4, "2099-00099", "DELA PEÑA", "ROSA", "SANTOS", "FEMALE",
                    "BACHELOR OF ARTS IN SOCIOLOGY", "1"))     # Student ID not in the system
    rows.append(row(5, "2026-00003", "CRUZ", "ANITA", "REYES", "FEMALE",
                    "BACHELOR OF SCIENCE IN COMPUTER SCIENCE", "2"))   # Ana's ID, another name
    rows.append(row(6, "2026-00001", "NAVARRO", "JUAN", "LOPEZ", "MALE",
                    "BACHELOR OF SCIENCE IN INFORMATION TECHNOLOGY", "4"))   # duplicate of row 2
    return rows


def rows_on_page_top(words):
    """Where the table starts on a page of make_pages (260 on the first page) and how many rows it has."""
    has_title = any(w[4] == "CONSOLIDATED" for w in words)
    top = 260 if has_title else 170
    controls = [w for w in words if len(w[4]) == 5 and w[4].isdigit() and w[0] < tes.LEFT + 120]
    return top, len(controls)


def pdf_from_pages(path, pages, digital=False):
    """Scanned-looking (pictures only) or digital (real text, drawn lines) PDF of the same pages."""
    doc = fitz.open()
    pw, ph = 949.68, 614.88
    sx, sy = pw / tes.WIDTH, ph / tes.HEIGHT
    for img, words in pages:
        page = doc.new_page(width=pw, height=ph)
        if digital:
            # table lines from the picture's dark pixels are not needed: draw the words as real text
            for x0, y0, x1, y1, text, _conf in words:
                size = max(4.5, (y1 - y0) * sy * 0.8)
                page.insert_text((x0 * sx, y1 * sy - 1), text, fontsize=size, fontname="helv")
            # the ruled lines of the table, as real (vector) lines like a PDF exported from Excel
            xs = [tes.LEFT + (tes.RIGHT - tes.LEFT) * p for p in tes.LINES]
            top, count = rows_on_page_top(words)
            for x in xs:
                page.draw_line((x * sx, top * sy), (x * sx, (top + count * tes.ROW_H) * sy), color=(0.2, 0.2, 0.2), width=0.6)
            for k in range(count + 1):
                y = (top + k * tes.ROW_H) * sy
                page.draw_line((tes.LEFT * sx, y), (tes.RIGHT * sx, y), color=(0.2, 0.2, 0.2), width=0.6)
        else:
            from io import BytesIO
            buf = BytesIO()
            img.convert("RGB").save(buf, "JPEG", quality=72)
            page.insert_image(page.rect, stream=buf.getvalue())
    doc.save(str(path))


def write_xlsx(path, rows, with_header=True, drop_id_for=None):
    from openpyxl import Workbook

    wb = Workbook()
    ws = wb.active
    ws.title = "Approved"
    ws.append(["TEST FILE - FICTIONAL APPROVED LIST (except the consenting test student)"])
    ws.append([f"TES Batch 2 - First Semester, Academic Year {SCHOOL_YEAR}"])
    ws.append([])
    ws.append(["Control Number", "Student Number", "TES Award Number", "Last Name", "Given Name", "Middle Initial",
               "Sex at Birth", "Degree Program", "Year Level", "TES Batch", "TES Amount", "TOTAL AMOUNT"])
    for r in rows:
        sid = "" if drop_id_for and r["control_number"] in drop_id_for else r["student_id"]
        ws.append([r["control_number"], sid, r["award_number"], r["last"], r["first"], r["middle"][:1],
                   r["sex"], r["program"], r["year_level"], r["batch"],
                   float(r["amount"].replace(",", "")), float(r["total_amount"].replace(",", ""))])
    wb.save(str(path))


def registrar_list(path):
    from openpyxl import Workbook

    wb = Workbook()
    ws = wb.active
    ws.title = "Enrolled"
    ws.append([f"TEST FILE - REGISTRAR ENROLLMENT LIST, 1st Semester AY {SCHOOL_YEAR}"])
    ws.append(["Student ID", "Last Name", "First Name", "Course", "Year Level", "Status"])
    ws.append([TEST["student_id"], TEST["last"].title(), TEST["first"].title(), TEST["program"].title(), "4th Year", "Enrolled"])
    for sid, last, first, _m, _s, program, year in DEMO[:3]:       # Pedro Garcia (2026-00004) is NOT enrolled
        ws.append([sid, last.title(), first.title(), program.title(), f"{year}th Year" if year not in "123" else
                   {"1": "1st Year", "2": "2nd Year", "3": "3rd Year"}[year], "Enrolled"])
    wb.save(str(path))


def problem_documents(folder):
    folder.mkdir(parents=True, exist_ok=True)

    # 1. A blank, speckled scan: nothing readable.
    img = Image.effect_noise((1240, 1754), 12).convert("L").point(lambda v: 235 if v > 100 else 225)
    buf = folder / "_blank.png"
    img.save(buf)
    doc = fitz.open()
    page = doc.new_page(width=595, height=842)
    page.insert_image(page.rect, filename=str(buf))
    doc.save(str(folder / "blank-unreadable-scan.pdf"))
    buf.unlink()

    # 2. Password-protected PDF (password: test123).
    doc = fitz.open()
    page = doc.new_page()
    page.insert_text((72, 100), "TEST FILE - Certificate of Indigency (fictional) - this PDF has a password", fontsize=11)
    doc.save(str(folder / "password-protected.pdf"), encryption=fitz.PDF_ENCRYPT_AES_256,
             owner_pw="owner123", user_pw="test123")

    # 3. Not really a PDF (a text file with a .pdf name).
    (folder / "broken-not-a-real-pdf.pdf").write_text("This is not a PDF file. TEST FILE.", encoding="utf-8")

    # 4. The wrong kind of document: a (fictional) library card.
    card = Image.new("RGB", (1400, 900), (245, 245, 240))
    d = ImageDraw.Draw(card)
    font, big = tes._font(42), tes._font(64)
    d.text((80, 60), "SAMPLE PUBLIC LIBRARY", fill=(20, 20, 20), font=big)
    d.text((80, 170), "LIBRARY BORROWER CARD", fill=(20, 20, 20), font=font)
    d.text((80, 300), f"Borrower: {TEST['first'].title()} {TEST['middle'][:1]}. {TEST['last'].title()}", fill=(20, 20, 20), font=font)
    d.text((80, 380), "Card No. LIB-0000-TEST", fill=(20, 20, 20), font=font)
    d.text((80, 460), "Valid for borrowing books only", fill=(20, 20, 20), font=font)
    d.text((80, 780), "TEST FILE - NOT AN OFFICIAL DOCUMENT", fill=(160, 20, 20), font=font)
    card.filter(ImageFilter.GaussianBlur(0.5)).save(folder / "library-card-wrong-document.png")

    # 5. Over the 10 MB upload limit (random pictures cannot be compressed).
    doc = fitz.open()
    rnd = random.Random(5)
    for _ in range(4):
        noise = Image.frombytes("RGB", (1600, 1100), bytes(rnd.getrandbits(8) for _ in range(1600 * 1100 * 3)))
        b = folder / "_noise.png"
        noise.save(b)
        page = doc.new_page()
        page.insert_image(page.rect, filename=str(b))
        b.unlink()
    doc.save(str(folder / "too-big-over-10MB.pdf"))


def main():
    lists = OUT / "lists"
    lists.mkdir(parents=True, exist_ok=True)
    good, problems = good_rows(), problem_rows()
    campus = "Caraga State University-Main Campus"

    good_pages = tes.make_pages(good, rows_per_page=12, tilt=0.3, split_last_row=False, school_year=SCHOOL_YEAR, campus=campus)
    pdf_from_pages(lists / "01-tes-billing-GOOD-scanned.pdf", good_pages)
    clean_pages = tes.make_pages(good, rows_per_page=12, tilt=0.0, split_last_row=False, school_year=SCHOOL_YEAR, campus=campus)
    pdf_from_pages(lists / "02-tes-billing-GOOD-digital.pdf", clean_pages, digital=True)

    # The printed total says 6 grantees x 10,000 = 60,000, but a wrong 50,000 is printed below (summed: 60,000).
    bad_pages = tes.make_pages(problems, rows_per_page=12, tilt=0.3, split_last_row=False, school_year=SCHOOL_YEAR, campus=campus)
    img, words = bad_pages[-1]
    d = ImageDraw.Draw(img)
    d.rectangle([590, tes.HEIGHT - 270, 1500, tes.HEIGHT - 215], fill=(250, 250, 250))
    d.text((600, tes.HEIGHT - 260), "Total Tertiary Education Subsidy: 50,000.00", fill=(20, 20, 20), font=tes._font(18))
    bad_pages[-1] = (img, words)
    pdf_from_pages(lists / "03-tes-billing-PROBLEMS-scanned.pdf", bad_pages)

    write_xlsx(lists / "04-approved-list-GOOD.xlsx", good)
    write_xlsx(lists / "05-approved-list-PROBLEMS.xlsx", problems + [
        row(7, "", "SANTOS", "MARIA", "CRUZ", "FEMALE", "BACHELOR OF SCIENCE IN INFORMATION TECHNOLOGY", "3")])
    registrar_list(lists / "06-registrar-enrollment-list.xlsx")
    problem_documents(OUT / "problem-documents")

    print(f"Test student on the lists: {TEST['first'].title()} {TEST['last'].title()} ({TEST['student_id']})")
    if TEST["student_id"] == "000-00000":
        print("WARNING: TEST_STUDENT_ID is not set in backend/.env, so the lists use 000-00000.")
    for path in sorted(OUT.rglob("*")):
        if path.is_file():
            print(f"  {path.relative_to(ROOT)}  ({path.stat().st_size // 1024} KB)")


if __name__ == "__main__":
    main()
