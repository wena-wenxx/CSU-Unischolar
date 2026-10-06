# CSU UniScholar — End-to-End Test Plan

Follow the steps in order. Each step says **what to do** and **what you should see**.
Tick the box when it passes. If a step fails, write down the exact message.

Demo accounts (created by `php artisan migrate:fresh --seed`, all fictional):

| Role | Email | Password |
|---|---|---|
| Staff (OAS) | oas.staff@carsu.edu.ph | Staff@12345 |
| Students (named scenarios) | student1@carsu.edu.ph … student10@carsu.edu.ph | Student@12345 |
| Students (bulk volume data) | s2024XXXXX@demo.carsu.edu.ph — e.g. open **Data Bank** to copy any student ID, then remove the dash: ID 2024-10234 → `s202410234@demo.carsu.edu.ph` | Student@12345 |

What the demo data contains (one scenario per student):

| Student | Login | Scholarship | Starts as |
|---|---|---|---|
| Juan | student1 | CMSP | Approved + enrollment verified → ready to tag |
| Maria | student2 | TES | Submitted; her Indigency upload is really a Barangay Clearance |
| Ana | student3 | DOST-SEI | Under review; also a completed past scholarship (Data Bank) |
| Pedro | student4 | LGU Butuan | Needs action (clearer Barangay Clearance) |
| Liza | student5 | CSU Student Assistance | Rejected by the agency (you will apply for the **CSU Cultural Grant (Choir)** as her) |
| Carlo | student6 | CSU Cultural Grant (Kayam Ethno Band) | Draft, 1 of 6 documents |
| Rosa | student7 | CSU Student Assistance | Complete; her COR belongs to another person |
| Mark | student8 | TES | Approved, enrollment **not** verified |
| Jose | student9 | TES | Active grantee, payroll entry **Ready** |
| Grace | student10 | CMSP | Active grantee, no payroll yet |

Besides these 10, the seeders add **120 more fictional students** with 3–6 applications each, so every
screen has realistic numbers right after `migrate:fresh --seed`:

| Table | Rows |
|---|---|
| Students | 130 |
| Scholarship programs | 20 (16 active, 3 closed, 1 inactive) |
| Applications | 560 (approved 99, complete 55, draft 43, needs action 49, rejected 162, submitted 81, under review 71) |
| Scholar records | 76 (active 61, completed 11, inactive 4) |
| Payroll entries | 109 (processed 62, draft 29, ready 18) over 3 semesters |
| Documents | 4,287 (validated 3,400, flagged 225, needs review 16, not yet checked 646) |
| AI check results | 3,641 |

---

## 0. Start everything (3 terminals in VS Code)

Open **Terminal → New Terminal** three times (or press **+** in the terminal panel).

**Terminal 1 — backend**
```
cd backend
php artisan migrate:fresh --seed
php artisan storage:link
php artisan serve
```
Expect: `Server running on [http://127.0.0.1:8000]`. (`storage:link` may say the link already exists — fine.)

> ⚠ `migrate:fresh` **erases all data** and rebuilds the demo data. Run it before every rehearsal.

**Terminal 2 — AI service**
```
cd ai-service
source .venv/bin/activate        # Windows: .venv\Scripts\activate
uvicorn main:app --port 8001
```
Expect: `Uvicorn running on http://127.0.0.1:8001`.
Optional check: http://127.0.0.1:8001/ocr-status — `"paddleocr_ready": true` means images (PNG/JPG) can be read.
Typed PDFs (like the demo files) are read without PaddleOCR.

**Terminal 3 — frontend**
```
cd frontend
npm install
npm run dev
```
Expect: `Local: http://localhost:5173/`. Open it in Chrome.

---

## Part A — Postman checks (API)

Every request needs the header **Accept: application/json**. After logging in, put the token in
**Authorization → Bearer Token**.

- [ ] **A1. Staff login** — `POST http://127.0.0.1:8000/api/login`, Body → x-www-form-urlencoded:
  `email=oas.staff@carsu.edu.ph`, `password=Staff@12345`. Expect **200**, a `token`, `"role": "staff"`.
- [ ] **A2. Student login** — same with `student5@carsu.edu.ph` / `Student@12345`. Expect **200**, `"role": "student"`.
- [ ] **A3. Wrong password** — A1 with `password=wrong`. Expect **422** "Invalid credentials."
- [ ] **A4. Scholarships** — `GET /api/scholarships` (student token). Expect **200** and **20** programs with `requirements`.
- [ ] **A5. Staff dashboard** — `GET /api/staff/dashboard` (staff token). Expect **200**, `total_applicants: 130`, `total_applications: 560`, `needs_action: 49`, `approved: 99`, `active_scholars: 61`, `payroll_ready: 18`, `ai_flags: 225`, `scholarships: 20`.
- [ ] **A6. Students are blocked** — A5 with the *student* token. Expect **403**.
- [ ] **A7. Data Bank** — `GET /api/staff/data-bank?q=2026-00003` (staff token). Expect **200** and Ana Demo Student in the list.

---

## Part B — Browser walkthrough (the full OAS workflow)

### Student: apply (Liza, student5)

- [ ] **B1. Login** — Open http://localhost:5173. Sign in as `student5@carsu.edu.ph` / `Student@12345`.
  Expect: address bar shows **/student/dashboard**, "Welcome, Liza".
- [ ] **B2. Details** — Click **Scholarships**, then **Details** on *CSU Cultural Grant (Choir)*.
  Expect: a page at **/student/scholarships/5** listing 6 required documents. Click **← All scholarships**.
- [ ] **B3. Apply** — Click **Apply** on CSU Cultural Grant (Choir).
  Expect: a page at **/student/applications/<number>** with the green note "Your application was saved as a draft…".
  **Submit application** is greyed out and "Still needed…" lists the 6 documents.
- [ ] **B4. Upload** — Choose a requirement, click **Choose File**, pick the matching PDF from
  `csu-unischolar-demo-files/students/2026-00005-liza-mendoza/`, click **Upload document**. Repeat for all 6
  (COR, Grades, Valid ID, Birth Certificate, Good Moral, Recommendation Letter).
  Expect: a green message "Document uploaded." in the bottom-right corner each time.
- [ ] **B5. Submit** — Click **Submit application**. A window asks "Submit application?" → click **Submit application**.
  Expect: corner message "Application submitted to OAS.", status **Submitted**, upload form gone.
- [ ] **B6. Back and refresh** — Click **My Applications**, then the browser **Back** button.
  Expect: you return to the application page. Press **F5** (refresh): you stay on the same page.
- [ ] **B7. Duplicate prevention** — Go to **Scholarships**, click **Apply** on the Choir grant again.
  Expect: a red corner message "You already applied to this scholarship."
- [ ] **B8. Staff pages are protected** — Type http://localhost:5173/staff/dashboard in the address bar.
  Expect: you are sent back to **/student/dashboard**. Click **Sign out**.

### Staff: review with AI help

- [ ] **B9. Staff login** — Sign in as `oas.staff@carsu.edu.ph` / `Staff@12345`.
  Expect: **/staff/dashboard** with 8 numbers: Total Applicants **130**, Applications **561** (560 + Liza's),
  Needs Action **49**, Approved **99**, Active Scholars **61**, Payroll Ready **18**, AI Flags **225**, Scholarship Programs **20**.
- [ ] **B10. AI check, good documents** *(AI service running)* — **Applications** → **Submitted (82)** filter →
  type `Liza` in the search box (it shows "1 of 561 applications") → **Review**.
  Click **Run AI check** on each document.
  Expect: each becomes **Validated** with "AI check · name match score … · No issues flagged."
- [ ] **B11. AI check, wrong document** — Close the window (×, Escape, or click outside). Search `2026-00002` → **Review** Maria →
  **Run AI check** on *Certificate of Indigency*.
  Expect: **Flagged** — "This does not look like the requested document (Certificate of Indigency)."
  Click **Text read by the AI** under the result: it opens and shows the text of the uploaded Barangay Clearance.
- [ ] **B12. AI check, wrong person** — **Complete** filter → search `2026-00007` → **Review** Rosa → **Run AI check** on the COR.
  Expect: **Flagged** — "Applicant name not found on the document (best match 47.06%)" and "Student ID number was not found".
  Then run the check on her *Parents' Income Tax Return*: **Validated** (for this document the AI only looks for the
  family surname, because the names on it are the parents').
- [ ] **B13. AI service offline** — Stop Terminal 2 (Ctrl+C), click **Re-run AI check**.
  Expect: red message "Unable to connect to AI service…", status **Needs Review**. Start Terminal 2 again.
- [ ] **B14. Needs Action** — Review Liza. Leave **Remarks** empty and click **Needs Action**:
  a red message asks you to type remarks. Type `Please upload a clearer Valid ID.`, click **Needs Action** again → status **Needs Action**.
- [ ] **B15. Record the agency's decision** — Click **Complete (forward to agency)**, then **Approved by agency**.
  Expect: status **Approved**, Enrollment "Not yet verified".

### Staff: enrollment → grantee → payroll

- [ ] **B16. Verify enrollment** — In the same window click **Verify enrollment**. A form asks
  "Is this student currently enrolled?" → keep **Yes, currently enrolled** → **Save verification**.
  Expect: "Enrollment verified. You can now tag this student as a grantee."
- [ ] **B17. Tag grantee** — Close the window. **Scholar Records** → *Ready to Tag as Grantee* lists 8 students,
  including Liza Demo Mendoza and Juan Demo Student.
  Click **Tag as Grantee** on Juan. Choose **Yes, has an ATM card** → **Tag as grantee**.
  Expect: "Juan Demo Student is now tagged as a grantee." and Juan under *Current Scholar Records*.
- [ ] **B18. One active scholarship rule** — Sign in as `student1` (Juan), click **Apply** on any other scholarship.
  Expect: "You already have an active scholarship. Only one active scholarship is allowed." Sign back in as staff.
- [ ] **B19. Batch payroll** — **Payroll** → click **Prepare payroll for all enrolled scholars (62)**.
  Type `5000` as the amount, keep the period → click **Prepare payroll for 62 scholars**.
  Expect: "Payroll prepared for 15 scholars. 47 skipped (already had "1st Semester AY 2026-2027")."
  (47 scholars, Jose among them, already had an entry for that period.)
- [ ] **B20. No double payment** — Click the batch button again with the same period.
  Expect: "Payroll prepared for 0 scholars. 62 skipped…"
- [ ] **B21. Payroll status** — Click **Mark Ready** on a Draft row (→ **Ready**), then **Mark Processed** (→ **Processed**).
- [ ] **B22. Data Bank** — **Data Bank** → type `2026-00003` → **Search** → **Full history** on Ana.
  Expect: Ana's completed CSU Student Assistance record with a processed payroll entry, and her current DOST-SEI application.

### Reports and settings

- [ ] **B23. CSV export** — **Reports** → **Export CSV** on each card. Expect 4 downloads and a corner message for each.
  Open them in Excel: names, IDs, scholarships and amounts appear in columns; "ñ" displays correctly.
- [ ] **B24. Edit a program** — **Scholarships** → **Manage**. Set **Amount** → **Save changes** ("Scholarship saved.").
  Click **Remove** on a requirement: a window asks "Remove requirement?" — click **Cancel**.

### Phone layout

- [ ] **B25.** In Chrome press **F12**, click the phone icon (Toggle device toolbar), choose a phone (e.g. iPhone 12 Pro).
  Expect: the green menu is hidden and a **☰** button appears top-left. Tap ☰ → the menu slides in; tap **Payroll** →
  the menu closes and the Payroll page shows. Wide tables scroll sideways inside their cards; the page itself does not.
  Forms (e.g. batch payroll) open from the bottom of the screen.

---

## What has and has not been tested

Tested by Claude on **6 Oct 2026** — Laravel 12 on **SQLite**, the React app in headless Chromium at 1366×900 and
390×844, after `migrate:fresh --seed` with the full volume data:
B1–B11, B14–B17, B19–B23, B24 (remove-requirement confirm) and B25 in the browser; A4–A5 numbers and B12 (Rosa's COR
flagged, her ITR validated) through the API; `php artisan test` (2 passed). Seeding twice does not duplicate anything.
The AI steps used a test server that runs the **real `validation.py` rules** on text extracted from the demo PDFs
(FastAPI and PyMuPDF could not be installed in Claude's environment). The **25 rule tests** in
`ai-service/tests/test_validation.py` pass (run with an exact re-implementation of RapidFuzz's `token_sort_ratio`);
the 4 HTTP endpoint tests were skipped.

**Not yet tested — please run these yourselves:**
- Everything on **MySQL** (your real database), including the seeders (about 10 seconds; 4,287 PDFs ≈ 21 MB).
- The **real AI service** (`uvicorn main:app`) and `python -m pytest` in `ai-service/`, including PaddleOCR on the PNG images.
- The real **React Router** library and `npm run dev` / `npm run build` / `npm run lint` with Vite.
  (Claude's test used a small stand-in for React Router because npm downloads were blocked.)
- The deployment files (`backend/Dockerfile`, `ai-service/Dockerfile`, `frontend/vercel.json`) — see `DEPLOYMENT.md`.
