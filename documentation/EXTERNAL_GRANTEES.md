# External Grantees (round 11)

Some students already hold a scholarship from an **outside party** (an LGU, a CHED list, a private foundation)
that is not processed in ScholarGuide. OAS staff upload that party's list. The AI reads it and suggests the matching
students, staff confirm, and the students are recorded as holding that outside scholarship.

This is different from **Approved Lists**, which records an agency's answer for programs that **are** in ScholarGuide
(CSV, matched by Student ID).

## How a list is read

| File | How it is read | Library |
|---|---|---|
| Excel `.xlsx` | every sheet, cell by cell | `openpyxl` (added to `requirements.txt`) |
| CSV | Python's `csv` module (comma, semicolon or tab) | built in |
| Word `.docx` | the tables; if none, one line per paragraph | `python-docx` (added) |
| PDF with text | the words and their positions on the page, put back into rows and columns | PyMuPDF (already installed, so PyPDF2 is not needed) |
| Scanned PDF, PNG, JPG | PaddleOCR, then the same rows-and-columns step | PaddleOCR (already installed) |
| Old `.xls` / `.doc` | refused, with "open it and Save As .xlsx / .docx" | — |

pandas is **not** used: it is a large download and these libraries already cover every file type.

The reader finds the header row (Name, Student ID, Surname, Given Name, M.I., …). Everything above it is treated as
a title. Without a header row, it guesses: a cell with a Student ID (like `2024-10123`), and a cell that looks like a
name. School years such as `2026-2027` are never taken as IDs.

**Honest limits:**
- Scanned lists and photos depend on OCR. Letters and columns can be misread, so every line is shown to staff
  before saving, with a warning.
- A PDF with very unusual layouts (rotated text, names split over two lines) may need the Excel copy instead.

## How each line is matched (`ai-service/list_reader.py`)

1. **The Student ID is found** in ScholarGuide → that student. If the name on the line looks very different
   (under 60%), the line is marked **Check**, not matched.
2. **There is no usable Student ID** → fuzzy name match with RapidFuzz `token_sort_ratio`:
   - "DELA CRUZ, JUAN S." and "Juan Santos Dela Cruz" match.
   - Accents and punctuation are ignored.
   - At **80% or more**, and clearly ahead of the next student → **Matched**.
   - Two students almost equally close → **Check**.
   - The line had a Student ID that is not in ScholarGuide → a name match is only a **Check**.
3. Nothing at 80% → **Did not match**, with the closest students (60% or more) as suggestions.
4. No name and no Student ID could be read → **Error**.
5. The same student on two lines → the second one is a **Check**.

## What staff do (Staff → External Grantees)

1. Type the scholarship name (and who gives it, the type and the school year), choose the file, and press **Upload
   and read**.
2. **Check the matches**:
   - a summary shows "X matched, Y to check, Z did not match, N errors"
   - every line shows what was read, the AI's result and score, and a dropdown for the student
   - Check lines must be chosen by staff (or found by typing a Student ID)
   - untick lines that are not grantees (titles, notes)
3. **Save confirmed list**, or **Discard upload**.

Nothing is saved before step 3. A list waiting for review stays there; it reopens when the page is opened again.

After saving:
- **Matched, active** students "hold an outside scholarship". It shows on:
  - their **My Profile** ("You hold … from …")
  - the **Data Bank** record and filters (*Holds an outside scholarship*, and the program list's *Outside
    scholarships* group)
  - **Reports** → External Grantees CSV
- It counts for the **one-active-scholarship rule**: the student cannot apply, cannot be tagged as a grantee, shows
  in Auto-Review as *Probably incomplete*, and fails the *No other scholarship* check in AI Recommendations.
- **Mark ended** when the outside scholarship stops; the rule then no longer applies.
- Did-not-match and error lines are kept for the record.

## Exports

| What | Where |
|---|---|
| The lines the AI read from a file (with result and score) | History → **Lines CSV** (also on the review card) |
| Matched students | Student list → *Matched students* → **Export CSV** |
| Unmatched lines | Student list → *Lines that did not match* → **Export CSV** |
| Every saved line | Reports → **External Grantees** |
| Upload history (file, program, counts, who, when) | History → **Export CSV** |
| The original file | History → **Original file** |

## Sample files

`php artisan demo:external-lists` writes 4 sample lists (xlsx, docx, pdf, csv) from your current database to
`backend/storage/app/demo-external-lists/`, with a README of the expected results. They use fictional students and
made-up program names; they are not any agency's official form.

## Files

| Part | Files |
|---|---|
| Database | `2026_10_23_000003_create_external_grantee_tables` (`external_grantee_uploads`, `external_grantees`) |
| AI service | `list_reader.py`, `main.py` (`POST /extract-grantee-list`), `tests/test_list_reader.py` |
| API | `ExternalGranteeController` (`/staff/external-grantees…`); one-scholarship rule in `Student::otherActiveScholarship()` |
| Pages | `pages/staff/ExternalGranteesPage.jsx`, Data Bank, My Profile, Reports |
| Demo | `ExternalGranteeSeeder`, `php artisan demo:external-lists`, `app/Support/DemoListFiles.php` |
