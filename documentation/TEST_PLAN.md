# CSU UniScholar — End-to-End Test Plan

Follow the steps in order. Each step says **what to do** and **what you should see**.
Tick the box when it passes. If a step fails, write down the exact message.

Demo accounts (created by `php artisan migrate:fresh --seed`, all fictional):

| Role | Email | Password |
|---|---|---|
| Staff (OAS) | oas.staff@carsu.edu.ph | Staff@12345 |
| Students | student1@carsu.edu.ph … student10@carsu.edu.ph | Student@12345 |

What the demo data contains (one scenario per student):

| Student | Login | Scholarship | Starts as |
|---|---|---|---|
| Juan | student1 | CMSP | Approved + enrollment verified → ready to tag |
| Maria | student2 | TES | Submitted; her Indigency upload is really a Barangay Clearance |
| Ana | student3 | DOST-SEI | Under review; also a completed past scholarship (Data Bank) |
| Pedro | student4 | LGU Butuan | Needs action (clearer Barangay Clearance) |
| Liza | student5 | CSU Student Assistance | Rejected by the agency |
| Carlo | student6 | CSU Cultural Grant | Draft, 1 of 3 documents |
| Rosa | student7 | CSU Student Assistance | Complete; her COR belongs to another person |
| Mark | student8 | TES | Approved, enrollment **not** verified |
| Jose | student9 | TES | Active grantee, payroll entry **Ready** |
| Grace | student10 | CMSP | Active grantee, no payroll yet |

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
- [ ] **A4. Scholarships** — `GET /api/scholarships` (student token). Expect **200** and **6** programs with `requirements`.
- [ ] **A5. Staff dashboard** — `GET /api/staff/dashboard` (staff token). Expect **200**, `scholarships: 6`, `active_scholars: 2`, `payroll_ready: 1`.
- [ ] **A6. Students are blocked** — A5 with the *student* token. Expect **403**.
- [ ] **A7. Data Bank** — `GET /api/staff/data-bank?q=ana` (staff token). Expect **200** and Ana in the list.

---

## Part B — Browser walkthrough (the full OAS workflow)

### Student: apply (Liza, student5)

- [ ] **B1. Login** — Open http://localhost:5173. Sign in as `student5@carsu.edu.ph` / `Student@12345`.
  Expect: address bar shows **/student/dashboard**, "Welcome, Liza".
- [ ] **B2. Details** — Click **Scholarships**, then **Details** on *Tertiary Education Subsidy (TES)*.
  Expect: a page at **/student/scholarships/3** listing 4 required documents. Click **← All scholarships**.
- [ ] **B3. Apply** — Click **Apply** on TES.
  Expect: a page at **/student/applications/<number>** with the green note "Your application was saved as a draft…".
  **Submit application** is greyed out and "Still needed…" lists the 4 documents.
- [ ] **B4. Upload** — Choose a requirement, click **Choose File**, pick the matching PDF from
  `csu-unischolar-demo-files/students/2026-00005-liza-mendoza/`, click **Upload document**. Repeat for all 4.
  Expect: a green message "Document uploaded." in the bottom-right corner each time.
- [ ] **B5. Submit** — Click **Submit application**. A window asks "Submit application?" → click **Submit application**.
  Expect: corner message "Application submitted to OAS.", status **Submitted**, upload form gone.
- [ ] **B6. Back and refresh** — Click **My Applications**, then the browser **Back** button.
  Expect: you return to the application page. Press **F5** (refresh): you stay on the same page.
- [ ] **B7. Duplicate prevention** — Go to **Scholarships**, click **Apply** on TES again.
  Expect: a red corner message "You already applied to this scholarship."
- [ ] **B8. Staff pages are protected** — Type http://localhost:5173/staff/dashboard in the address bar.
  Expect: you are sent back to **/student/dashboard**. Click **Sign out**.

### Staff: review with AI help

- [ ] **B9. Staff login** — Sign in as `oas.staff@carsu.edu.ph` / `Staff@12345`.
  Expect: **/staff/dashboard** with 8 numbers (Scholarship Programs **6**, Active Scholars **2**, Payroll Ready **1**).
- [ ] **B10. AI check, good documents** *(AI service running)* — **Applications** → **Submitted** filter → **Review** on Liza.
  Click **Run AI check** on each document.
  Expect: each becomes **Validated** with "AI check · name match score … · No issues flagged."
- [ ] **B11. AI check, wrong document** — Close the window (×, Escape, or click outside). **Review** Maria →
  **Run AI check** on *Certificate of Indigency*.
  Expect: **Flagged** — "This does not look like the requested document (Certificate of Indigency)."
- [ ] **B12. AI check, wrong person** — **Complete** filter → **Review** Rosa → **Run AI check** on the COR.
  Expect: **Flagged** — "Applicant name not found on the document" and "Student ID number was not found".
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
- [ ] **B17. Tag grantee** — Close the window. **Scholar Records** → Liza and Juan are under *Ready to Tag as Grantee*.
  Click **Tag as Grantee** on Juan. Choose **Yes, has an ATM card** → **Tag as grantee**.
  Expect: "Juan Demo Student is now tagged as a grantee." and Juan under *Current Scholar Records*.
- [ ] **B18. One active scholarship rule** — Sign in as `student1` (Juan), click **Apply** on any other scholarship.
  Expect: "You already have an active scholarship. Only one active scholarship is allowed." Sign back in as staff.
- [ ] **B19. Batch payroll** — **Payroll** → click **Prepare payroll for all enrolled scholars (3)**.
  Type `5000` as the amount, keep the period → click **Prepare payroll for 3 scholars**.
  Expect: "Payroll prepared for 2 scholars. 1 skipped (already had "1st Semester AY 2026-2027")."
  (Jose already had an entry for that period.)
- [ ] **B20. No double payment** — Click the batch button again with the same period.
  Expect: "Payroll prepared for 0 scholars. 3 skipped…"
- [ ] **B21. Payroll status** — Click **Mark Ready** on a Draft row (→ **Ready**), then **Mark Processed** (→ **Processed**).
- [ ] **B22. Data Bank** — **Data Bank** → type `ana` → **Search** → **Full history**.
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

Tested by Claude on 4 Oct 2026 — Laravel 12 on **SQLite**, the React app in headless Chromium at 1366×900 and
390×844, after `migrate:fresh --seed`:
B1–B11, B14–B17 and B19–B25 in the browser, and A1–A7, B12, B13 and B18 through the API.
The AI steps used a test server that runs the **real `validation.py` rules** on text extracted from the demo PDFs
(FastAPI and PyMuPDF could not be installed in Claude's environment). The **19 rule tests** in
`ai-service/tests/test_validation.py` pass (run with an exact re-implementation of RapidFuzz's `token_sort_ratio`).

**Not yet tested — please run these yourselves:**
- Everything on **MySQL/MariaDB** (your real database).
- The **real AI service** (`uvicorn main:app`) and `pytest` in `ai-service/`, including PaddleOCR on the PNG images.
- The real **React Router** library and `npm run dev` / `npm run build` / `npm run lint` with Vite.
  (Claude's test used a small stand-in for React Router because npm downloads were blocked.)
