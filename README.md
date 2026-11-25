# ScholarGuide — CSU UniScholar

A scholarship management system for the **Office of Admission and Scholarship (OAS), Caraga State University**.
BSIT capstone project by Wena Rose N. Contiga and Gleslimie B. Binag.

Students find scholarships they may qualify for, upload their documents once, apply and follow every step online.
OAS staff check documents with AI help, forward applications, read the agencies' approved lists (including the
scanned CHED TES billing file), verify enrollment, tag grantees, prepare payroll and print the General Payroll
(Form 6-A). **The AI only suggests and highlights. People decide.**

---

## What each role can do

| Student | OAS staff | Admin |
|---|---|---|
| Browse open scholarships, see which ones match (checklist, no percentages) | Dashboard of what needs attention | Staff and student accounts |
| Upload documents once, reuse them | **AI Recommendations**: students who may qualify, with reasons; send a scholarship notice | System settings (school year, signatories, office details) |
| Apply, follow each step, fix what OAS asks | Review applications; **AI document check**; forward to the agency | Activity log (who did what) |
| Bell notifications, e-mail on approval | **Approved Lists** (Excel, Word, CSV, PDF, scans, photos; TES billing file) | Scholarships overview, reports |
| Contact OAS and read replies | Enrollment check against the Registrar's list; tag grantees | |
| Scholarship history, profile, video guide | Payroll and Form 6-A (Word/PDF); External Grantees; Data Bank; Reports; Announcements; Messages | |

The steps: **program & rules → AI Recommendations → notice → student applies → AI check → staff review →
forwarded to the agency → approved list → enrollment verified → tagged as grantee → payroll → Form 6-A → reports.**
After each step the system says what comes next and where to click.

---

## Setting up (once per computer)

Install:
- Git
- VS Code
- **XAMPP 8.2** (PHP + MySQL)
- Composer
- Node.js LTS
- **Python 3.11**
- Optional: Ollama, for the memo reader

In `C:\xampp\php\php.ini`, remove the `;` before `extension=zip` and `extension=gd`, then restart the computer.
In XAMPP, start **MySQL**, open **Admin** and create the database `csu_unischolar`.

| Step | PowerShell | Git Bash |
|---|---|---|
| Get the code | `cd C:\Projects` then `git clone https://github.com/wena-wenxx/CSU-Unischolar.git` | `cd /c/Projects` then the same `git clone` |
| Backend | `cd C:\Projects\CSU-Unischolar\backend` · `composer install` · `copy .env.example .env` · `php artisan key:generate` · `php artisan migrate:fresh --seed` · `php artisan storage:link` | same, with `/c/Projects/...` and `cp .env.example .env` |
| Website | `cd ..\frontend` · `npm install` | `cd ../frontend` · `npm install` |
| AI service | `cd ..\ai-service` · `python -m venv .venv` · `.venv\Scripts\activate` · `pip install -r requirements.txt` · `pip install -r requirements-ocr.txt` | same, but `source .venv/Scripts/activate` |

`requirements-ocr.txt` installs **PaddleOCR** (about 1 GB). It is needed for **scans and photos** (scanned CORs, the
TES billing file). If it will not install, **Tesseract** also works: install it from the UB-Mannheim build, then
`pip install pytesseract`.

### The real test student (with her permission)
Her details go in `backend/.env` only, never in GitHub:
```
TEST_STUDENT_EMAIL=her CSU e-mail
TEST_STUDENT_ID=her Student ID
TEST_STUDENT_COURSE="Bachelor of Science in ..."
TEST_STUDENT_YEAR_LEVEL="4th Year"
TEST_STUDENT_COLLEGE="College of ..."
TEST_STUDENT_PHOTO=C:/Users/.../her-photo.jpg
```
Then run `php artisan migrate:fresh --seed` again. Until these are filled in, placeholders are used.

E-mail: by default e-mails are only written to the log. For real sending, use a Gmail **App Password** and always
set `MAIL_ALWAYS_TO` to your own inbox, so demo students never receive mail.

## Running it (every time)

Open three terminals:
```
backend:     php artisan serve
ai-service:  (activate .venv first)  uvicorn main:app --port 8001
frontend:    npm run dev
```
Then open **http://localhost:5173**.

| Demo account | E-mail | Password |
|---|---|---|
| OAS staff | oas.staff@carsu.edu.ph | Staff@12345 |
| Admin | admin@carsu.edu.ph | Admin@12345 |
| Test student (Gleslimie B. Binag) | `TEST_STUDENT_EMAIL` in `.env` | Student@12345 |
| Fictional student (Juan Navarro) | student1@carsu.edu.ph | Student@12345 |

With `DEMO_LOGIN_ACCOUNTS=true` in `.env`, the login page shows these accounts; click one to fill it in.
Set it to `false` for real use. The other demo students are fictional (student2–student10, plus 120 bulk students).

## When your partner pushes changes
```
git pull
cd backend   && composer install && php artisan migrate && php artisan test
cd frontend  && npm install
cd ai-service && (activate .venv) && pip install -r requirements.txt
```
`php artisan migrate` keeps your data. `php artisan migrate:fresh --seed` **erases** it and reloads the demo data.

## Faster reading of scanned lists

- Excel and Word lists are read by Laravel itself and take a second. If one seems slow, the backend is
  still busy with an earlier request: `php artisan serve` handles one request at a time, and a scanned PDF keeps
  it busy until it is read, even after **Cancel reading**. Wait for it, or stop the backend (Ctrl+C) and start it again.
- Scanned PDFs: PaddleOCR reads each page; cells it is unsure of are read a second time with Tesseract, which is
  much faster. Install Tesseract once (`winget install -e --id UB-Mannheim.TesseractOCR`) and restart the AI service.
  To read those cells with PaddleOCR again (slower), set `OCR_CELL_ENGINE=paddle` before starting the AI service.
- Reading the same file again returns the saved result at once, until the AI service is restarted.

## Testing

| What | Command | Expected |
|---|---|---|
| Backend | `php artisan test` | all passed (77 tests, including a 20-student hand-checked eligibility set) |
| AI service | `python -m pytest` | all passed |
| Website build | `npm run build` | "built in …" |

There are fictional samples to try:
- `docs/samples/demo-tes-billing-batch2-FICTIONAL.pdf`: a scanned-looking TES billing file made from the demo
  students. Use it in Approved Lists after `migrate:fresh --seed`.
- `php artisan demo:approved-lists` and `php artisan demo:external-lists` write sample lists in every format.

---

## The "AI" parts, honestly

| Part | What it really is | Machine learning? | When it is unsure |
|---|---|---|---|
| Eligibility score (AI Recommendations) | The program's rules (GWA, income, year level, college, one scholarship) plus required documents, as points. Student-typed data counts half until OAS verifies it. Scores update by themselves; nobody scores students by hand. | **No**: rules, so the same data always gives the same result | "Insufficient information"; staff decide |
| Field fit | A small pretrained text model (BAAI/bge-small) comparing the student's course and interests with the program; TF-IDF if the model is not installed | **Yes** (pretrained, runs offline) | It only ranks and explains, never decides |
| Memo reader | Ollama `qwen2.5:1.5b` reading the program memo; simple pattern reading without it | **Yes** (local language model) | Every value is shown with its sentence; staff tick what is right |
| Document check | OCR (PaddleOCR, or Tesseract when PaddleOCR is not installed) + fuzzy name match + keywords + Student ID + school year of a COR + age of a certificate + the child named on a parent's document | OCR is **deep learning**; the checks are rules | Flags go to staff; an unreadable file goes to manual review |
| List reader (Approved Lists, External Grantees, Enrollment) | Word/Excel/CSV read directly; scans read by finding the table lines (OpenCV) and OCR per cell; known CHED forms (TES billing) have their own column pattern | OCR is **deep learning**; the table reading is rules | Unsure cells are highlighted; totals, missing control numbers and duplicates are checked; staff can edit any cell |

**Why no cloud AI API?** Students' documents and data would leave the office, it would need internet and money,
and the results could not be checked line by line. Everything here runs on the office's computer. Structured
files need no AI at all; scans need OCR, which runs offline.

**Accuracy is measured, not promised.** On the real TES billing scan, using Tesseract in testing, all 88 rows were
placed correctly. Of 208 cells checked by hand, 10 were wrong, and 9 of those were highlighted. Re-measure with
PaddleOCR on your computer. See the appendix.

## Privacy

- Student documents are private, opened only through links that expire.
- Accounts by role; sign-in attempts are limited; the AI never approves anything.
- Real files with students' data (TES billing, real CORs, survey answers) go in `private-test-data/`, which Git
  ignores, and never into the demo data.
- `.env` (passwords, the test student's details) is never committed.

## Folders

| Folder | What is inside |
|---|---|
| `backend/` | Laravel API: `app/Http/Controllers`, `app/Models`, `app/Support` (eligibility engine, payroll form, readers), `database/migrations`, `database/seeders` (+ `demo-files`), `tests/Feature` |
| `frontend/` | React website: `src/pages/{student,staff,admin}`, `src/components`, `src/lib`, `public/` (CSU logo, campus photo, announcement pictures, video guides) |
| `ai-service/` | Python service: `validation.py` (document check), `ocr_engine.py`, `list_reader.py` + `ruled_table.py` (lists and scanned forms), `fit_matcher.py`, `rule_reader.py`, `samples/`, `tests/` |
| `tools/` | Video-guide recorder (`tutorials/`), survey analysis (`survey/`) |
| `docs/` | `samples/` (sample scholarship memo) |

## Credits

- CSU name, logo and campus photo: Caraga State University (campus photo © CSU MIS/ICT Photography), used for this
  capstone for the CSU Office of Admission and Scholarship.
- Open-source software: Laravel, React, Vite, Tailwind CSS, FastAPI, PaddleOCR, Tesseract, OpenCV, PyMuPDF,
  RapidFuzz, dompdf, Ollama with Qwen2.5.
