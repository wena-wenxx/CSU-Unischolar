"""
Tests for list_reader.py (External Grantees). The people below are fictional.
Run inside ai-service/ with the virtual environment active:  python -m pytest
"""
import pytest

from list_reader import (
    extract_and_match,
    table_rows,
    group_boxes,
    match_records,
    name_score,
    normalize_id,
    parse_records,
    read_table,
)

STUDENTS = [
    {"id": 1, "student_id": "2024-10123", "first_name": "Juan", "middle_name": "Santos", "last_name": "Dela Cruz"},
    {"id": 2, "student_id": "2025-11456", "first_name": "Maria", "middle_name": "Reyes", "last_name": "Garcia"},
    {"id": 3, "student_id": "221-00462", "first_name": "Wena Rose", "middle_name": "Nunez", "last_name": "Contiga"},
    {"id": 4, "student_id": "2026-12001", "first_name": "Mark", "middle_name": "", "last_name": "Ramos"},
    {"id": 5, "student_id": "2026-12002", "first_name": "Marc", "middle_name": "", "last_name": "Ramos"},
]


# ---------- small helpers ----------
def test_normalize_id_accepts_spaces_and_short_csu_ids():
    assert normalize_id("ID: 2024 - 10123") == "2024-10123"
    assert normalize_id("221-00462") == "221-00462"
    assert normalize_id("09171234567") == ""          # a phone number is not an ID


def test_name_score_ignores_order_commas_and_accents():
    juan = STUDENTS[0]
    assert name_score("DELA CRUZ, JUAN S.", juan) >= 90
    assert name_score("Juan Santos Dela Cruz", juan) == 100
    assert name_score("Pedro Penaflor", juan) < 60


def test_group_boxes_rebuilds_rows_and_columns():
    # Two rows; a big gap between "2024-10123" and "Dela" starts a new column.
    boxes = [
        (40, 100, 70, 110, "1"), (100, 100, 160, 110, "2024-10123"),
        (220, 100, 245, 110, "Dela"), (248, 100, 275, 110, "Cruz,"), (278, 100, 300, 110, "Juan"),
        (40, 130, 70, 140, "2"), (100, 130, 160, 140, "2025-11456"),
        (220, 130, 260, 140, "Garcia,"), (263, 130, 295, 140, "Maria"),
    ]
    rows = group_boxes(boxes)
    assert rows == [["1", "2024-10123", "Dela Cruz, Juan"], ["2", "2025-11456", "Garcia, Maria"]]


# ---------- parsing ----------
def test_parse_with_header_and_split_name_columns():
    rows = [
        ["LIST OF GRANTEES - CITY SCHOLARSHIP"],
        ["No.", "Student No.", "Last Name", "First Name", "Middle Name"],
        ["1", "2024-10123", "Dela Cruz", "Juan", "Santos"],
        ["2", "", "Garcia", "Maria", "R."],
        ["No.", "Student No.", "Last Name", "First Name", "Middle Name"],   # header again (page 2)
        ["Total: 2"],
    ]
    records, has_header = parse_records(rows)
    assert has_header
    assert [(r["name"], r["student_id"]) for r in records[:2]] == [
        ("Dela Cruz, Juan Santos", "2024-10123"),
        ("Garcia, Maria R.", ""),
    ]
    assert records[-1]["name"] == "" and "Total" in records[-1]["raw"]   # read, but nothing usable


def test_parse_without_header_guesses_columns():
    rows = [
        ["Republic of the Philippines"],
        ["1.", "2024-10123", "DELA CRUZ, JUAN S.", "BS Information Technology"],
        ["2.", "Maria R. Garcia", "BS Nursing"],
        ["Prepared by: ________"],
    ]
    records, has_header = parse_records(rows)
    assert not has_header
    assert [(r["name"], r["student_id"]) for r in records] == [
        ("DELA CRUZ, JUAN S.", "2024-10123"),
        ("Maria R. Garcia", ""),
    ]


# ---------- matching ----------
def records_of(*pairs):
    return [{"row": i + 1, "name": n, "student_id": s, "raw": f"{s} {n}", "note": ""} for i, (n, s) in enumerate(pairs)]


def test_match_by_student_id_and_by_name():
    out = match_records(records_of(("Dela Cruz, Juan", "2024-10123"), ("GARCIA, MARIA R.", "")), STUDENTS)
    assert out[0]["result"] == "matched" and out[0]["method"] == "student_id" and out[0]["student"]["id"] == 1
    assert out[1]["result"] == "matched" and out[1]["method"] == "name" and out[1]["student"]["id"] == 2
    assert out[1]["score"] >= 80


def test_id_found_but_different_name_goes_to_check():
    out = match_records(records_of(("Pedro Penaflor", "2024-10123")), STUDENTS)
    assert out[0]["result"] == "check"
    assert "looks different" in out[0]["note"]


def test_unknown_id_with_matching_name_is_only_a_check():
    out = match_records(records_of(("Juan Dela Cruz", "2024-99999")), STUDENTS)
    assert out[0]["result"] == "check" and out[0]["student"]["id"] == 1


def test_two_almost_equal_names_go_to_check():
    out = match_records(records_of(("Mark Ramos", "")), STUDENTS[3:5] + [{"id": 6, "student_id": "x", "first_name": "Mark", "last_name": "Ramos"}])
    assert out[0]["result"] == "check"
    assert "similar name" in out[0]["note"]


def test_unmatched_and_error_rows():
    out = match_records(records_of(("Someone Else Entirely", ""), ("", "")), STUDENTS)
    assert out[0]["result"] == "unmatched"
    assert out[1]["result"] == "error"


def test_same_student_twice_is_flagged():
    out = match_records(records_of(("Juan Dela Cruz", "2024-10123"), ("Dela Cruz, Juan", "")), STUDENTS)
    assert out[0]["result"] == "matched"
    assert out[1]["result"] == "check" and "listed twice" in out[1]["note"]


# ---------- whole files ----------
def test_csv_file(tmp_path):
    path = tmp_path / "list.csv"
    path.write_text("Student ID,Name\n2024-10123,\"Dela Cruz, Juan S.\"\n221-00462,Contiga Wena Rose\n,Nobody Known Here\n", encoding="utf-8")
    result = extract_and_match(str(path), "list.csv", STUDENTS)
    assert result["method"] == "csv"
    assert result["summary"] == {"total": 3, "matched": 2, "check": 0, "unmatched": 1, "error": 0}


def test_excel_file(tmp_path):
    openpyxl = pytest.importorskip("openpyxl")
    book = openpyxl.Workbook()
    sheet = book.active
    sheet.append(["LGU Scholarship - AY 2026-2027"])
    sheet.append(["No", "Student Number", "Surname", "Given Name", "M.I."])
    sheet.append([1, "2025-11456", "Garcia", "Maria", "R"])
    sheet.append([2, "2024-10123", "Dela Cruz", "Juan", "S"])
    path = tmp_path / "list.xlsx"
    book.save(path)

    result = extract_and_match(str(path), "list.xlsx", STUDENTS)
    assert result["method"] == "excel"
    assert [r["student"]["id"] for r in result["rows"]] == [2, 1]


def test_word_file_with_table(tmp_path):
    docx = pytest.importorskip("docx")
    document = docx.Document()
    document.add_paragraph("List of Scholars")
    table = document.add_table(rows=1, cols=3)
    table.rows[0].cells[0].text, table.rows[0].cells[1].text, table.rows[0].cells[2].text = "No.", "Name of Scholar", "ID No."
    for i, (name, sid) in enumerate([("Contiga, Wena Rose N.", "221-00462"), ("Ramos, Mark", "2026-12001")], start=1):
        cells = table.add_row().cells
        cells[0].text, cells[1].text, cells[2].text = str(i), name, sid
    path = tmp_path / "list.docx"
    document.save(path)

    result = extract_and_match(str(path), "list.docx", STUDENTS)
    assert result["method"] == "word-table"
    assert [r["result"] for r in result["rows"]] == ["matched", "matched"]


def test_digital_pdf_table(tmp_path):
    fitz = pytest.importorskip("pymupdf")
    doc = fitz.open()
    page = doc.new_page()
    page.insert_text((72, 72), "CITY SCHOLARSHIP GRANTEES", fontsize=12)
    y = 110
    for no, sid, name in [("No.", "Student ID", "Name"), ("1", "2024-10123", "DELA CRUZ, JUAN S."), ("2", "2025-11456", "GARCIA, MARIA R.")]:
        page.insert_text((72, y), no, fontsize=11)
        page.insert_text((120, y), sid, fontsize=11)
        page.insert_text((260, y), name, fontsize=11)
        y += 22
    path = tmp_path / "list.pdf"
    doc.save(str(path))
    doc.close()

    result = extract_and_match(str(path), "list.pdf", STUDENTS)
    assert result["method"] == "pdf-text"
    assert [r["student"]["id"] for r in result["rows"]] == [1, 2]


def test_unsupported_type(tmp_path):
    path = tmp_path / "list.txt"
    path.write_text("hello")
    with pytest.raises(ValueError):
        read_table(str(path), "list.txt")


def test_table_rows_for_column_picking(tmp_path):
    path = tmp_path / "enrolled.csv"
    path.write_text("Student Number,Name,Course\n2024-10123,Dela Cruz Juan,BSIT\n", encoding="utf-8")
    result = table_rows(str(path), "enrolled.csv")
    assert result["rows"] == [["Student Number", "Name", "Course"], ["2024-10123", "Dela Cruz Juan", "BSIT"]]
