"""The built-in Word / Excel readers (used when openpyxl / python-docx are not installed).
The files are made here with the standard library, so the test needs nothing else."""
import zipfile

import pytest

import list_reader as L

XLSX_FILES = {
    "[Content_Types].xml": '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"/>',
    "xl/workbook.xml": '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
                       'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
                       '<sheets><sheet name="List" sheetId="1" r:id="rId1"/></sheets></workbook>',
    "xl/_rels/workbook.xml.rels": '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                                  '<Relationship Id="rId1" Target="worksheets/sheet1.xml"/></Relationships>',
    "xl/sharedStrings.xml": '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
                            '<si><t>Student No.</t></si><si><t>Name</t></si><si><r><t>NAVARRO, </t></r><r><t>Juan L.</t></r></si></sst>',
    "xl/styles.xml": '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
                     '<cellXfs><xf numFmtId="0"/><xf numFmtId="14"/></cellXfs></styleSheet>',
    "xl/worksheets/sheet1.xml": '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'
                                '<row r="1"><c r="A1" t="s"><v>0</v></c><c r="B1" t="s"><v>1</v></c><c r="D1" t="inlineStr"><is><t>Date</t></is></c></row>'
                                '<row r="2"><c r="A2"><v>202600001</v></c><c r="B2" t="s"><v>2</v></c><c r="D2" s="1"><v>46317</v></c></row>'
                                '</sheetData></worksheet>',
}
DOCX_XML = ('<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body><w:tbl>'
            '<w:tr><w:tc><w:p><w:r><w:t>Student No.</w:t></w:r></w:p></w:tc>'
            '<w:tc><w:tcPr><w:gridSpan w:val="2"/></w:tcPr><w:p><w:r><w:t>Name</w:t></w:r></w:p></w:tc></w:tr>'
            '<w:tr><w:tc><w:p><w:r><w:t>2026-00001</w:t></w:r></w:p></w:tc><w:tc><w:p><w:r><w:t>NAVARRO</w:t></w:r></w:p></w:tc>'
            '<w:tc><w:p><w:r><w:t>Juan L.</w:t></w:r></w:p></w:tc></w:tr></w:tbl></w:body></w:document>')


def _zip(path, files):
    with zipfile.ZipFile(path, "w") as z:
        for name, text in files.items():
            z.writestr(name, text)
    return str(path)


def test_builtin_excel_reader(tmp_path):
    rows = L._read_xlsx_builtin(_zip(tmp_path / "list.xlsx", XLSX_FILES))
    assert rows[0] == ["Student No.", "Name", "", "Date"]
    assert rows[1] == ["202600001", "NAVARRO, Juan L.", "", "2026-10-22"]   # number ID and Excel date


def test_builtin_word_reader_keeps_columns_lined_up(tmp_path):
    rows, method = L._read_docx_builtin(_zip(tmp_path / "list.docx", {"word/document.xml": DOCX_XML}))
    assert method == "table"
    assert rows == [["Student No.", "Name", ""], ["2026-00001", "NAVARRO", "Juan L."]]


def test_a_renamed_old_file_gets_a_clear_message(tmp_path):
    fake = tmp_path / "old.xlsx"
    fake.write_bytes(b"\xd0\xcf\x11\xe0" + b"\0" * 100)     # how an old .xls starts
    with pytest.raises(ValueError, match="Save As"):
        L._read_xlsx_builtin(str(fake))
