# CSU UniScholar — End-to-End Test Plan

Follow the steps in order. Each step says **what to do**, **what to send**, and **what you should see**.
Tick the box when it passes. If a step fails, write down the exact error message.

Demo accounts (created by `php artisan migrate:fresh --seed`):

| Role | Email | Password |
|---|---|---|
| Staff (OAS) | oas.staff@carsu.edu.ph | Staff@12345 |
| Student 1 (Juan) | student1@carsu.edu.ph | Student@12345 |
| Student 2 (Maria) | student2@carsu.edu.ph | Student@12345 |
| Student 3 (Ana) | student3@carsu.edu.ph | Student@12345 |

---

## 0. Start everything (3 terminals in VS Code)

Open **Terminal → New Terminal** three times (or press the **+** in the terminal panel).

**Terminal 1 — backend**
```
cd backend
php artisan migrate:fresh --seed
php artisan storage:link
php artisan serve
```
Expect: `Server running on [http://127.0.0.1:8000]`. (`storage:link` may say the link already exists — that is fine.)

> ⚠ `migrate:fresh` **erases all data**. Only run it when you want a clean demo database.

**Terminal 2 — AI service**
```
cd ai-service
source .venv/bin/activate        # Windows: .venv\Scripts\activate
uvicorn main:app --port 8001
```
Expect: `Uvicorn running on http://127.0.0.1:8001`.
Check: open http://127.0.0.1:8001/ocr-status — `"paddleocr_ready": true` means scanned images can be read.
(Typed PDFs are read without PaddleOCR.)

**Terminal 3 — frontend**
```
cd frontend
npm install
npm run dev
```
Expect: `Local: http://localhost:5173/`. Open that address in your browser.

---

## Part A — Postman checks (API)

In Postman, every request needs the header **Accept: application/json**.
After logging in, put the token in **Authorization → Bearer Token**.

- [ ] **A1. Staff login** — `POST http://127.0.0.1:8000/api/login`, Body → x-www-form-urlencoded: `email=oas.staff@carsu.edu.ph`, `password=Staff@12345`.
  Expect **200**, `"message": "Login successful"`, a `token`, and `"role": "staff"`. Save this as the *staff token*.
- [ ] **A2. Student login** — same request with `student1@carsu.edu.ph` / `Student@12345`.
  Expect **200** and `"role": "student"`. Save as the *student token*.
- [ ] **A3. Wrong password** — send A1 with `password=wrong`. Expect **422** "Invalid credentials."
- [ ] **A4. Scholarships** — `GET /api/scholarships` with the student token. Expect **200** and **6** programs, each with a `requirements` list.
- [ ] **A5. Staff dashboard** — `GET /api/staff/dashboard` with the staff token. Expect **200** with `total_applicants`, `needs_action`, `approved`, `active_scholars`, `payroll_ready`, `ai_flags`, `scholarships` (= 6).
- [ ] **A6. Students are blocked from staff data** — A5 with the *student* token. Expect **403**.
- [ ] **A7. Data Bank search** — `GET /api/staff/data-bank?q=juan` with the staff token. Expect **200** and Juan in the list.

---

## Part B — Browser walkthrough (the full OAS workflow)

### Student: apply

- [ ] **B1. Student login** — Open http://localhost:5173, type `student1@carsu.edu.ph` / `Student@12345`, click **Sign in**.
  Expect: Student Dashboard, "Welcome, Juan", **Available Scholarships = 6**.
- [ ] **B2. Browse scholarships** — Click **Scholarships** in the left menu. Click **Details** on any card.
  Expect: a window listing the program's requirements, each marked *Required*. Close it with **×**.
- [ ] **B3. Create application** — Click **Apply** on *CHED Merit Scholarship Program (CMSP)*.
  Expect: a window opens with the message "Your application was saved as a draft…" and status **Draft**.
  The **Submit application** button is greyed out, and the text lists the documents still needed.
- [ ] **B4. Upload documents** — In the window: choose a requirement in **Select requirement**, click **Choose File**, pick a PDF/JPG/PNG (max 10 MB), click **Upload document**. Repeat for every requirement.
  Expect: "Document uploaded." each time and a new row under **Documents** with status **Uploaded**.
  Tip: for a good AI demo, run `python make_sample_docs.py` in `ai-service/` and use the PDFs in `ai-service/sample_docs/`.
- [ ] **B5. Submit** — When nothing is listed as "Still needed", click **Submit application**, then **OK**.
  Expect: "Application submitted to OAS." and status **Submitted**. The upload section disappears.
- [ ] **B6. My Applications** — Click **My Applications**. Expect the CMSP row with today's date and **Submitted**.
- [ ] **B7. Duplicate prevention** — Go to **Scholarships** and click **Apply** on CMSP again.
  Expect: "You already applied to this scholarship."
- [ ] Click **Sign out**.

### Staff: review documents with AI help

- [ ] **B8. Staff login** — Sign in as `oas.staff@carsu.edu.ph` / `Staff@12345`.
  Expect: Staff Dashboard with **Total Applicants 1**, **Applications 1**.
- [ ] **B9. Open the application** — Click **Applications**, then **Review** on Juan's row.
  Expect: Juan's details, the documents (click a file name to open it in a new tab), "AI check: not run yet" under each.
- [ ] **B10. AI validation** *(AI service must be running)* — Click **Run AI check** on a document.
  Expect one of:
  - "AI check finished: no issues found." → document status **Validated**.
  - "…possible issues flagged…" → status **Flagged** and the reasons listed in orange (e.g. name not found).
  - "The file could not be read automatically…" → status **Needs Review** (check it yourself).
  The AI never approves or rejects — staff decide.
- [ ] **B11. AI service offline** — Stop Terminal 2 (Ctrl+C), click **Re-run AI check**.
  Expect: "Unable to connect to AI service…" and status **Needs Review**. Start Terminal 2 again.
- [ ] **B12. Needs Action** — Type in **Remarks**: `Please upload a clearer Valid ID.` Click **Needs Action**.
  Expect status **Needs Action**. (As Student 1 later, **My Applications → Continue** shows "OAS says: …" and allows re-upload and re-submit.)
- [ ] **B13. Record the agency's decision** — Click **Complete (forward to agency)**, then **Approved by agency**.
  Expect status **Approved**; the Enrollment field says **Not yet verified**.

### Staff: enrollment → grantee → payroll

- [ ] **B14. Verify enrollment** — In the same window click **Verify enrollment**, then **OK** (= currently enrolled).
  Expect: "Enrollment verified. You can now tag this student as a grantee." and Enrollment **Verified (today)**.
  (Clicking **Cancel** records "NOT currently enrolled", and the student cannot be tagged.)
- [ ] **B15. Tag grantee** — Close the window. Click **Scholar Records**. Juan is under **Ready to Tag as Grantee**.
  Click **Tag as Grantee**, answer the ATM question (OK = Yes).
  Expect: Juan in **Current Scholar Records** with status **Active**, Enrolled **Yes**.
- [ ] **B16. One active scholarship rule** — Sign in as Student 1 and click **Apply** on any other scholarship.
  Expect: "You already have an active scholarship. Only one active scholarship is allowed." Sign back in as staff.
- [ ] **B17. Prepare payroll** — Click **Payroll**. On Juan's row click **Add to Payroll**.
  Type an amount (e.g. `5000`) → OK, keep or change the period → OK.
  Expect a row under **Payroll Records** with status **Draft**.
- [ ] **B18. Payroll status** — Click **Mark Ready** (status → **Ready**), then **Mark Processed** (status → **Processed**).
  The Dashboard's **Payroll Ready** counts entries currently in *Ready*.
- [ ] **B19. Data Bank** — Click **Data Bank**, type `juan`, click **Search**, then **Full history**.
  Expect: Juan's profile, his grantee record with payroll entries, and his application with "Verified …".

### Reports

- [ ] **B20. CSV export** — Click **Reports**. Click **Export CSV** on each card.
  Expect 4 downloads: `scholarship-applications.csv`, `scholar-records.csv`, `payroll-report.csv`, `scholarship-programs.csv`.
  Open each in Excel: student names, IDs, scholarships and amounts appear in columns; "ñ" displays correctly.
- [ ] **B21. Edit a program** — Click **Scholarships → Manage** on a program. Set **Amount**, click **Save changes** ("Saved.").
  Add a requirement, then **Remove** it. Adding a requirement that is already listed shows "This scholarship already lists that requirement."

---

## What has and has not been tested

Tested by Claude on 4 Oct 2026 — Laravel 12 on **SQLite**, the React app in headless Chromium,
and a **stand-in AI service** that returns the same JSON shape as `ai-service/main.py`:

- In the browser: B1–B6, B8–B10, B12–B15, B17–B21.
- Through the API: A1–A7, B7, B11, B12 (re-upload + re-submit), B14 (not-enrolled path), B16,
  B18 (Processed), and tagging one student for two approved scholarships (second one is refused).

**Not yet tested — please run these yourselves:**
- Everything on **MySQL/MariaDB** (your real database).
- The **real AI service** with PaddleOCR on real scanned documents (B10), and `pytest` in `ai-service/`.
- `npm run build` / `npm run lint` with Vite (the app was compiled with esbuild for the test instead).
