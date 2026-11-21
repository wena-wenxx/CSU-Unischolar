# CSU UniScholar – Full Test Guide (student → staff → TES billing → payroll)

Use this guide to test the whole system on your laptop. Each step says what to do and what you should see.

**Privacy:** Gleslimie's real documents stay in `private-test-data\` (never in GitHub, never in screenshots for the paper without covering her details). Everyone else in the test files is fictional.

---

## 0. One-time setup

### 0.1 Install the OCR program (needed for photos and scanned PDFs)

Her documents and the scanned TES list are pictures, so an OCR program must be installed. Tesseract is the easiest on Windows. In the VS Code terminal (PowerShell):

```powershell
winget install -e --id UB-Mannheim.TesseractOCR
cd C:\Projects\CSU-Unischolar\ai-service
pip install -r requirements.txt        # now includes pytesseract
```

The system finds `C:\Program Files\Tesseract-OCR\tesseract.exe` by itself. Close and reopen the terminal, then restart the AI service:

```powershell
cd C:\Projects\CSU-Unischolar\ai-service; python -m uvicorn main:app --port 8001
```

Open **http://127.0.0.1:8001/health** in the browser. You should see:

- `"ocr_engine": "tesseract"` (or `"paddleocr"`) — **not** "none". If it says none, Tesseract is not installed yet.
- `text_matching`: `tfidf`, or `bge` if you installed `requirements-ai.txt`. Both work.
- `memo_reader`: the pattern reader, or Ollama if it is running. Both work.

PaddleOCR is optional and needs Python 3.11 or 3.12 (`pip install -r requirements-ocr.txt`).

### 0.2 Settings in `backend\.env`

```
DEMO_LOGIN_ACCOUNTS=false        # no demo accounts on the login page
MAIL_MAILER=log                  # e-mail off (CSU accounts need university authorization)
DEMO_RESET_ALLOWED=true          # only on the demo laptop
TEST_STUDENT_EMAIL=...           # Gleslimie's CSU e-mail
TEST_STUDENT_ID=...              # her real Student ID (the lists use it)
TEST_STUDENT_FIRST_NAME=Gleslimie
TEST_STUDENT_MIDDLE_NAME=B.
TEST_STUDENT_LAST_NAME=Binag
```

Then run:

```powershell
cd C:\Projects\CSU-Unischolar\backend; php artisan config:clear
```

### 0.3 Put the files in place

```powershell
cd C:\Projects\CSU-Unischolar
mkdir private-test-data\gleslimie -Force
# copy her 5 files there: cirth_certificae.pdf, indigency.pdf, good_moral.pdf, tax_retunr_1.pdf, tax_retunr.pdf
cd ai-service; python samples\make_test_files.py; cd ..
```

`make_test_files.py` prints her name and Student ID, then the files it made in `private-test-data\generated\`:

| File | What it is |
|---|---|
| `lists\01-tes-billing-GOOD-scanned.pdf` | Scanned-looking TES billing (needs OCR): Gleslimie + 4 demo students, total 50,000 |
| `lists\02-tes-billing-GOOD-digital.pdf` | The same list as a clear PDF with real text (no OCR) |
| `lists\03-tes-billing-PROBLEMS-scanned.pdf` | Unknown Student ID, a wrong name on Ana's ID, a duplicate row, wrong printed total |
| `lists\04-approved-list-GOOD.xlsx` | The good list in Excel |
| `lists\05-approved-list-PROBLEMS.xlsx` | The problems in Excel, plus a row with no Student ID (matched by name) |
| `lists\06-registrar-enrollment-list.xlsx` | The Registrar's list. Pedro Garcia (2026-00004) is **not** on it. |
| `problem-documents\*` | Blank scan, password-protected PDF, broken file, wrong document, a file over 10 MB |

### 0.4 Start clean

```powershell
cd C:\Projects\CSU-Unischolar\backend; php artisan demo:reset     # type RESET DEMO
```

This leaves an empty application queue, the 10 named demo students (`student1`…`student10@carsu.edu.ph`), and Gleslimie (never removed).

### Logins

| Who | E-mail | Password |
|---|---|---|
| OAS staff | oas.staff@carsu.edu.ph | Staff@12345 |
| Admin | admin@carsu.edu.ph | Admin@12345 |
| Gleslimie (real test student) | TEST_STUDENT_EMAIL in .env | Student@12345 |
| Demo students | student1@carsu.edu.ph … student10@carsu.edu.ph | Student@12345 |

---

## 1. Staff: prepare a test program (5 minutes)

Gleslimie has 4 kinds of documents, so make a program that asks for exactly those.

1. Log in as **staff** → **Scholarships** → add a program:
   - **Name:** *OAS Test Grant (testing only)*, short name *TEST*
   - **Mode:** OAS processes it; status **open**; amount **5,000**; period from today to next month
   - **Requirements:** Birth Certificate, Certificate of Indigency, Certificate of Good Moral Character, Parents' Income Tax Return
   - **Eligibility rules (optional):** income at most 300,000
   - Save.
2. **Processing Batches** → you should see no batch yet. One is made automatically with the first application, or press **New batch**.

---

## 2. Student: Gleslimie

Log in as **Gleslimie**.

| # | Do this | You should see |
|---|---|---|
| 2.1 | **My Profile:** enter GWA and annual family income (e.g. 40,000, as on her indigency) | Saved. Dashboard shows programs that may fit. |
| 2.2 | **Scholarships:** open *OAS Test Grant* | The 4 required documents |
| 2.3 | **My Documents:** upload `cirth_certificae.pdf` as **Birth Certificate**, then press **Run AI check** | **No issue.** Name found (100). Status: AI found no issues. |
| 2.4 | Upload `indigency.pdf` as **Certificate of Indigency** → Run AI check | Name found, **but flagged:** "dated July 6, 2022, more than 6 months ago". Correct: it is a 2022 certificate. |
| 2.5 | Upload `good_moral.pdf` as **Good Moral** → Run AI check | Name found; flagged as dated July 1, 2022 (old). Correct. |
| 2.6 | Upload `tax_retunr_1.pdf` as **Parents' Income Tax Return** → Run AI check | Surname found (parents' document). Flagged as dated July 7, 2022, more than 12 months ago. Correct. |
| 2.7 | Apply to *OAS Test Grant* (documents are reused) → Submit | Status **Submitted**, next step shown |

### Problem tests (as Gleslimie)

Upload into a slot, then press **Run AI check**.

| # | File | Slot | Expected |
|---|---|---|---|
| P1 | `indigency.pdf` | Birth Certificate | "This does not look like the requested document" |
| P2 | `library-card-wrong-document.png` | Certificate of Indigency | "Does not look like the requested document" |
| P3 | `blank-unreadable-scan.pdf` | any | "Very little text could be read" → goes to manual review |
| P4 | `password-protected.pdf` | any | "The PDF is password-protected; ask for a copy without a password" |
| P5 | `broken-not-a-real-pdf.pdf` | any | Refused at upload (not a real PDF), or "could not be read" |
| P6 | `too-big-over-10MB.pdf` | any | Refused: over the 10 MB limit |
| P7 | `tax_retunr.pdf` (4 pages: all her documents in one file) | Parents' ITR | Surname found; flagged for the 2022 date. Staff should ask for the right single document. |

Delete the problem uploads afterwards (or leave them; they never block the right ones).

### Other-person and sibling tests

| # | Do this | Expected |
|---|---|---|
| P8 | Log in as **student1** (Juan Navarro). Upload Gleslimie's `indigency.pdf` as his Certificate of Indigency → Run AI check | "Applicant name not found on the document" |
| P9 | As **Admin** → Manage Students → add a fictional sibling: Glenda B. Binag, ID 2026-90001, e-mail glenda.binag.test@carsu.edu.ph. Log in as her, upload `tax_retunr_1.pdf` as Parents' ITR → Run AI check | "This parent's document was issued for 'GLESLIMIE B BINAG', not for the applicant" |
| P10 | Still as Glenda, upload `cirth_certificae.pdf` as Birth Certificate | Name "similar but not exact" or "not found" (a different first name) |

---

## 3. Staff: one application from start to finish

Log in as **staff**.

| # | Page | Do this | You should see |
|---|---|---|---|
| 3.1 | AI Recommendations | Recalculate; filter *OAS Test Grant* | Gleslimie listed, with the "Why?" explanation |
| 3.2 | AI Recommendations | Send a notice to a demo student | Their bell shows the notice. The screen says e-mail is off (log only). |
| 3.3 | Applications | Open the page | Default list **"Still needs action"**: Gleslimie's submitted application is there |
| 3.4 | Applications | Open her application | Her 4 documents with AI results and reasons; 3 are flagged for the old date |
| 3.5 | Applications | Ask for a correction (Needs Action) on the indigency, with a remark | The student's bell shows the request; status **Needs action** |
| 3.6 | Applications | After checking the documents yourself, press **Verify** on each, then forward (**Complete**) | The application **leaves** the "Still needs action" list. It is under *Forwarded to agency* (choose **All statuses** to see everything). Nothing is deleted. |
| 3.7 | Forwarded to Agency | Export Excel or PDF | File downloads |
| 3.8 | Applications | Record the agency's decision: **Approved** | Status **Approved**; it is back in "Still needs action" because enrollment is not verified yet |
| 3.9 | Enrollment | Upload `06-registrar-enrollment-list.xlsx`, then **Verify All Enrollments** | Gleslimie: enrolled (method: Registrar's list) |
| 3.10 | Scholar Records | Tag Gleslimie as grantee (ATM status) | Scholar record AY 2026-2027, active |
| 3.11 | Payroll | Prepare a period, e.g. "TEST 1st Semester 2026-2027" → Mark ready → **General Payroll** | Form 6-A with her name and 5,000. PDF and Word work. |
| 3.12 | Payroll | Prepare the same period again | "Nobody to add … already in payroll for this period" (no duplicate) |
| 3.13 | Processing Batches | Open the TEST batch → **Mark completed** | Completed; counts stay; nothing deleted |
| 3.14 | Scholar Records | Set Gleslimie's TEST record to **completed** (the grant ended) | She is now a *former* scholar of TEST. This is needed for part 4 (one active scholarship rule). |
| 3.15 | Data Bank | Search "Binag"; filter **Grantee status: Completed** | Gleslimie found; **Full history** shows the application, batch, payroll and every step with who did it |

---

## 4. Final test: TES billing file → payroll

| # | Page | Do this | You should see |
|---|---|---|---|
| 4.1 | Processing Batches | **New batch:** TES, 2026-2027, 1st Semester | "TES - 1st Semester AY 2026-2027", open |
| 4.2 | Approved Lists | Program **TES**, batch from 4.1, file `01-tes-billing-GOOD-scanned.pdf` | Read with OCR (about 1 minute with Tesseract). AY 2026-2027, 1st Semester, Batch 2 filled in; 5 rows; total 50,000 = printed. A few highlighted cells: compare them with the PDF. |
| 4.3 | Approved Lists | **Process** | 5 × "New approved application" (Gleslimie, Juan, Maria, Ana, Pedro). The message says e-mail is off. |
| 4.4 | Approved Lists | Upload `02-tes-billing-GOOD-digital.pdf` (same program) | "How the pages were read: 1 page from the PDF's own text" (no OCR). Process: 5 × "Already approved" (no duplicates). |
| 4.5 | Approved Lists | Upload `01-…scanned.pdf` **again** | "Exactly the same file … was already processed" → press **Cancel** |
| 4.6 | Approved Lists | Upload `03-tes-billing-PROBLEMS-scanned.pdf` | Warning "Rows sum to 60,000 but the printed total is 50,000" → tick "I reviewed". Process: 2099-00099 **unmatched**; Ana's ID with "ANITA CRUZ" → **check: name differs**; duplicate Juan row → **error**. |
| 4.7 | Approved Lists | Upload history → Details → **Set aside** the unmatched row (reason: "Not a CSU student") | Row stays with the note; "To resolve" goes down |
| 4.8 | Approved Lists | Optional: `05-approved-list-PROBLEMS.xlsx` | The row with no Student ID ("SANTOS, MARIA") is matched by name |
| 4.9 | Enrollment | Upload the registrar list again → **Verify All** | Gleslimie, Juan, Maria, Ana: **enrolled**. Pedro: **not on the list → not enrolled**. |
| 4.10 | Scholar Records | Tag the 4 enrolled students | Tagged. Pedro **cannot** be tagged (enrollment not verified). |
| 4.11 | Payroll | Prepare "TES 1st Semester 2026-2027", program TES | 4 entries × 10,000 (amount from the billing list) = 40,000 → Mark ready → **General Payroll Form 6-A** → print or PDF → Mark processed |
| 4.12 | Payroll | Prepare the same period again | Nobody added (no duplicate payroll) |
| 4.13 | Processing Batches | Open the TES batch | Lists 3, grantees 4, payroll 4 processed. **Mark completed** (if the set-aside/duplicate rows are still open, write a reason). |
| 4.14 | Data Bank | Gleslimie → Full history | TEST (completed, 2026-2027) and TES (active, 2026-2027), each with its batch; TES payroll 10,000; processing history |
| 4.15 | Student view | Log in as Gleslimie | TES application **Approved**; bell notifications for each step |

### Optional: the real file

Repeat 4.2 with the real `TES_BATCH_2_BILLING001.pdf` (keep it in `private-test-data\`). With Tesseract you should get 88 rows; the reader reports the missing control numbers. Its students are not in the demo database, so they show as **unmatched**. That is expected.

---

## 5. After testing

- `php artisan demo:reset` (type RESET DEMO) for an empty queue, or `php artisan demo:reset --full` for the full demo history. Gleslimie, staff, admin and programs are kept.
- **Optional cleanup:**
  - Delete the *OAS Test Grant* program in Scholarships, or leave it closed.
  - Delete the sibling test account (Glenda) in Admin → Manage Students.

### If something is different

| You see | Do this |
|---|---|
| "No OCR program is installed" | Repeat 0.1 and check `/health` |
| AI check says "AI service returned an error" | The AI service is not running |
| Her name is not found on her own document | Send me the AI result text (**Read the text the AI reads**), not the document |
