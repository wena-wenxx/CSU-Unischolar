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
| Ana | student3 | TDP-SUC | Under review; also a completed past SA scholarship (Data Bank) |
| Pedro | student4 | TDP-TES | Needs action (clearer Certificate of Indigency) |
| Liza | student5 | Student Assistantship (SA) | Rejected (you will apply for **Culture and Arts** as her) |
| Carlo | student6 | Culture and Arts | Draft, 1 of 6 documents |
| Rosa | student7 | Student Assistantship (SA) | Complete; her COR belongs to another person |
| Mark | student8 | TES | Approved, enrollment **not** verified |
| Jose | student9 | TES | Active grantee, payroll entry **Ready** |
| Grace | student10 | CMSP | Active grantee, no payroll yet |

Besides these 10 (and Wena's test account), the seeders add **120 more fictional students** with 1–3 applications each, so every
screen has realistic numbers right after `migrate:fresh --seed`:

| Table | Rows |
|---|---|
| Students | 131 |
| Scholarship programs | 13, as listed by the OAS: 6 applied for through the OAS (4 CHED-funded, 2 university-funded) and 7 agency-direct |
| Applications | 262 (approved 94, complete/forwarded 40, draft 13, needs action 15, rejected 40, submitted 30, under review 30) |
| Scholar records | 80 (active 62, completed 12, inactive 6) |
| Payroll entries | 106 (processed 68, draft 19, ready 19) over 3 semesters |
| Documents | 1,744 (validated 1,399, flagged 66, needs review 6, not yet checked 273) |
| AI check results | 1,471 |

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
- [ ] **A4. Scholarships** — `GET /api/scholarships` (student token). Expect **200** and **12** programs with `requirements` (CMSP is closed, so students do not see it).
- [ ] **A5. Staff dashboard** — `GET /api/staff/dashboard` (staff token). Expect **200**, `total_applicants: 130`, `total_applications: 560`, `needs_action: 49`, `approved: 99`, `active_scholars: 61`, `payroll_ready: 18`, `ai_flags: 225`, `scholarships: 20`.
- [ ] **A6. Students are blocked** — A5 with the *student* token. Expect **403**.
- [ ] **A7. Data Bank** — `GET /api/staff/data-bank?q=2026-00003` (staff token). Expect **200** and Ana Demo Student in the list.

---

## Part B — Browser walkthrough (the full OAS workflow)

### Student: apply (Liza, student5)

- [ ] **B1. Login** — Open http://localhost:5173. Sign in as `student5@carsu.edu.ph` / `Student@12345`.
  Expect: a **Welcome to ScholarGuide** window with 5 steps (press **Next** … **Got it**; it does not appear again in
  this browser), then **/student/dashboard**, "Welcome, Liza", and 3 announcements.
- [ ] **B2. Details** — Click **Scholarships**, then **Details** on *Culture and Arts*.
  Expect: a page at **/student/scholarships/5** listing 6 required documents. Click **← All scholarships**.
- [ ] **B3. Apply** — Click **Apply** on Culture and Arts.
  Expect: a page at **/student/applications/<number>** with the green note "Your application was saved as a draft…".
  A checklist shows the 6 required documents ("0 of 6 uploaded"); **Submit application** is **grey** and disabled.
- [ ] **B4. Upload** — On each checklist row, click **Choose File**, pick the matching PDF from
  `csu-unischolar-demo-files/students/2026-00005-liza-mendoza/`, then click that row's **Upload**. Do all 6
  (COR, Grades, Valid ID, Birth Certificate, Good Moral, Recommendation Letter).
  Expect: "<document> uploaded." each time, a green ✓ on the row and the progress bar filling. After the 6th,
  **Submit application** turns **green** and the hint says "All required documents are uploaded."
- [ ] **B5. Submit** — Click **Submit application**. A window asks "Submit application?" → click **Submit application**.
  Expect: "Application submitted to OAS.", status **Submitted**, the upload buttons are gone, a **What happens next?**
  box appears, and the timeline shows "Application started → Submitted to OAS" with the next steps faded.
- [ ] **B6. Back and refresh** — Click **My Applications**, then the browser **Back** button.
  Expect: you return to the application page. Press **F5** (refresh): you stay on the same page.
- [ ] **B7. Duplicate prevention** — Go to **Scholarships** and find Culture and Arts.
  Expect: its button now says **View my application** instead of Apply. (The server also refuses a second
  application with "You already applied to this scholarship.")
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
  Expect: Ana's completed SA record with a processed payroll entry, and her current TDP-SUC application.

### Reports and settings

- [ ] **B23. CSV export** — **Reports** → **Export CSV** on each card. Expect a download and a corner message for each
  (the **List for the Agency** card first needs one program chosen at the top).
  Open them in Excel: names, IDs, scholarships and amounts appear in columns; "ñ" displays correctly.
- [ ] **B24. Edit a program** — **Scholarships** → **Manage**. Set **Amount** → **Save changes** ("Scholarship saved.").
  Click **Remove** on a requirement: a window asks "Remove requirement?" — click **Cancel**.

### Phone layout

- [ ] **B25.** In Chrome press **F12**, click the phone icon (Toggle device toolbar), choose a phone (e.g. iPhone 12 Pro).
  Expect: the green menu is hidden and a **☰** button appears top-left. Tap ☰ → the menu slides in; tap **Payroll** →
  the menu closes and the Payroll page shows. Wide tables scroll sideways inside their cards; the page itself does not.
  Forms (e.g. batch payroll) open from the bottom of the screen.

---

## Part C — New features (round 5)

Run `php artisan migrate:fresh --seed` first. Numbers below are what Claude saw on 6 Oct 2026; dates and "days left"
change with the real date.

**Deadlines and programs**
- [ ] **C1.** As Liza: **Scholarships** shows filter buttons *All (16) · Government (1) · CSU-funded (5) · LGU (1) ·
  Private / Foundation (9)*, a deadline on every card, and a coloured label (Open / Open · N days left / Opens …).
- [ ] **C2.** As staff: **Scholarships → Manage** on *Culture and Arts* → set **Deadline** to
  yesterday → **Save changes**. The table shows **Closed · deadline passed**. Log in as Carlo (student6): Culture and Arts is no longer
  in Scholarships, and his Culture and Arts draft says the deadline has passed with **Submit** disabled. Set the deadline back after.
- [ ] **C3.** As staff: create a program with **Type**, **Applications open** and **Deadline** → it appears in the table.
  In its **Manage** window press **Delete** → "Scholarship deleted." Open **Manage** on TES: **Delete** is disabled
  ("This program has 71 application(s)…").

**Student features**
- [ ] **C4.** As Jose (student9): a green **Current scholarship** banner (TES, latest payroll *Ready*). In Scholarships the
  message "You are currently a grantee of Tertiary Education Subsidy (TES). You cannot apply…" and every button says
  **Already a grantee**.
- [ ] **C5.** The 🔔 bell shows a number for the named demo students. Click it: a list of updates; the number disappears.
- [ ] **C6.** **My Profile**: the Registrar fields have no input boxes (🔒 read-only). Contact number `0917123` →
  error; `09171234567` → "Contact number updated." **Request a correction** (Course) → "Request sent to OAS" and a row
  under *My correction requests*.
- [ ] **C7.** **Help** shows six student topics; **Show the welcome guide again** reopens the 5-step guide.
- [ ] **C8.** Every page except the dashboards has **← Back** above the title. On a page opened by typing its address,
  it goes to the dashboard.

**Staff features**
- [ ] **C9.** **Dashboard → Recent activity** lists the latest changes ("Submitted to OAS just now"); **Profile correction
  requests** shows the pending ones. **Mark resolved** with "apply this change" ticked → "Change applied to the student
  record and request resolved."
- [ ] **C10.** **Applications** has **Submitted** and **Last activity** columns (e.g. "draft → Submitted to OAS · just now"),
  a program dropdown (CMSP → 44 of 561) and a sort dropdown (Latest activity first is the default).
- [ ] **C11.** **Announcements → New announcement** → title, message, *Show until* → **Post**. Log in as a student: it is
  the first of the 3 announcements on the dashboard. The expired demo notice shows only to staff.
- [ ] **C12.** **Agency Lists**: choose *Culture and Arts*, upload a CSV with a Student ID column (use
  **Download sample template** to see the layout), check the guessed columns, **Process**. Expect a summary such as
  "Matched 2 student(s), 1 unmatched, 1 error(s)" and one line per row (a grantee of another program is an error, an unknown
  ID is unmatched). The upload appears under **Upload history**.
- [ ] **C13.** **Reports**: choose *CHED Merit Scholarship Program (CMSP)* → file names change to
  `applications-CMSP-<date>.csv` etc.; **List for the Agency** downloads a numbered list of complete/approved applicants.

---

## Part D — Round 6 features

Run `php artisan migrate:fresh --seed` first. In `backend/.env` set `QUEUE_CONNECTION=sync` and `MAIL_MAILER=log`.

**My Documents (student5 Liza)**
- [ ] **D1.** Menu → **My Documents**: 9 cards (COR, Grades, Valid ID, Indigency, Birth Certificate, Barangay Clearance,
  Good Moral, ITR, Recommendation). Liza has 8 valid; Birth Certificate says "Not uploaded yet".
- [ ] **D2.** On Birth Certificate choose `2026-00004-pedro-garcia/birth-certificate.pdf` (someone else's) → **Upload** →
  **Check with AI**. Expect the orange box "Applicant name not found on the document (best match 52.63%)" and the badge
  **Needs attention**.
- [ ] **D3.** On COR choose Liza's COR → **Replace** → "Certificate of Registration (COR) saved." **Show 1 older copy**
  lists the previous file.
- [ ] **D4.** Scholarships → **Apply** on Culture and Arts. A gold box says "You already have 5 of these documents in My
  Documents" → **Use my saved documents** → "5 saved documents added", 5 of 6 uploaded, **Submit** still grey. The
  Birth Certificate row shows **Use saved file** with "⚠ The AI flagged this saved file". Upload Liza's own birth
  certificate on that row → Submit turns green → submit.
- [ ] **D5.** Log in as Ana (student3) → My Documents: the Certificate of Indigency card is red, "Expired on Mar 3, 2026".

**Search, cards, sidebar, profile**
- [ ] **D6.** Search box (top bar): student types `my doc` → "Go to My Documents"; staff types `juan` → three students →
  click *Juan Student (2026-00001)* → Data Bank opens his history. `CHED` → two programs → click → Manage window.
  `payroll` + Enter → Payroll page.
- [ ] **D7.** Staff dashboard: every number is a link. Needs Action → Applications with *Needs action (49)* selected;
  AI Flags (documents) 225 "In 179 applications" → *Has AI flags (179)*; Payroll Ready → Payroll with *Ready (18)*.
  Student dashboard: Being Processed → My Applications with *Being processed* selected.
- [ ] **D8.** Quick actions: staff *Create Scholarship* → the create form with the Name box focused; student
  *My Documents* → My Documents.
- [ ] **D9.** Top of the menu: initials avatar, name, Student ID (staff: e-mail) and role → click → View Profile / Help /
  Sign Out all work.
- [ ] **D10.** My Profile (student): no input boxes for Registrar fields; each has **Request a change** → the form below
  is pre-filled with that field and the cursor is in "Correct value". A "My documents" box shows 8 of 9 valid.

**E-mail**
- [ ] **D11.** Staff → review Rosa (2026-00007) → **Approved by agency**. Toasts: "Status changed…" and "Approval e-mail
  sent to student7@carsu.edu.ph." Reports → **E-mails sent** shows the row with **SENT**. Open
  `backend/storage/logs/laravel.log`: the e-mail with "Hello Rosa Villanueva (2026-00007)", "APPROVED" and a link
  `http://localhost:5173/student/applications/…`.
- [ ] **D12.** Uploading an agency list that approves students adds "N approval e-mail(s) sent." to the summary.

**Colours**
- [ ] **D13.** The sidebar is deep forest green (#004d26) with gold (#d4af37) highlights; the page background is soft gray.
  On a wide screen the sidebar is 280px and stays in place while the page scrolls; on a phone the ☰ menu still works.

---

## Part E — Round 7 features

Run `php artisan migrate:fresh --seed` first. Staff: `oas.staff@carsu.edu.ph` / `Staff@12345`.
Test student: **Wena Rose Contiga** `wenarose.contiga@carsu.edu.ph` / `Student@12345` (starts with no applications).

**Review steps in order**
- [ ] **E1.** Applications → filter **Submitted** → Review. A gold "Next step" box explains what to do. Only
  *Mark under review*, *Needs action*, *Complete: forward to agency* and *Save remarks only* are shown
  (no Approved/Rejected yet).
- [ ] **E2.** Press *Needs action* with empty Remarks → red message, nothing changes.
- [ ] **E3.** Press *Complete: forward to agency* → now *Approved by agency* / *Rejected by agency* appear.
  Press *Approved by agency* → *Undo agency decision* and *Verify enrollment* appear. A student already tagged as
  grantee shows no Undo button.
- [ ] **E4.** Filter **Drafts** → Review → no status buttons and no Remarks box.
- [ ] **E5.** The review window shows college, e-mail, contact number, other applications, scholarships held,
  each document's upload date and "valid until", and the application timeline. The table has an **AI flags** column.

**Scholarship creation with requirements**
- [ ] **E6.** Scholarships → fill the form, press *Create Scholarship* with no document ticked → red message.
- [ ] **E7.** Search "grade" → only *Certificate of Grades* shows. Tick it; tick *Valid ID* and set it to *Optional*
  with a note. Type "certificate of grades" under *Other requirement* → it is not added twice. Add a custom
  "Essay" → it shows a **Custom** badge. Create → "Scholarship created with 3 requirements."
- [ ] **E8.** Manage the new program → change *Valid ID* to *Required* → saved. *Add more requirements* lists only the
  documents not yet on the program.

**ATM status**
- [ ] **E9.** Scholar Records → columns *Has ATM*, *ATM funds*, *If no ATM*. Filter *No ATM yet*.
- [ ] **E10.** *ATM status* on a row → choose *No ATM card yet* → *Other* → type a status → Save → the row shows it.
- [ ] **E11.** Log in as `student10@carsu.edu.ph` → My Profile → a read-only **My stipend** card shows the ATM status.

**Payroll**
- [ ] **E12.** Payroll → *1. Prepare payroll*: Period is a dropdown (current term marked). With *All programs*, the
  amount says it is filled in from each program. *Preview payroll* → included / skipped / total / ATM warnings.
- [ ] **E13.** *Confirm* → the list below shows the new drafts. Preview again for the same period → everyone is
  *Skipped: Already in payroll for this period*.
- [ ] **E14.** Tick the header box → *Mark Ready*. Filter *Ready* + *No ATM yet* → the total row adds up.
  *Export CSV* downloads the shown rows; *Print / Save as PDF* opens the print window with the CSU letterhead.
- [ ] **E15.** Choose one program → its amount is filled in; change it → an orange "Different from the program
  amount" note. *3. Payroll history* → *View* filters the list to that period and program.

**Data Bank**
- [ ] **E16.** 25 students, A to Z by last name; *Show more* adds 25. Filters: college, year level, sex, standing,
  program. *Export CSV (n)* downloads every match. Search "Contiga" → Wena; *Full history* shows Sex: Female.

**Menu and test account**
- [ ] **E17.** Staff menu: Dashboard, Applications, Approved Lists, Scholar Records, Payroll, Scholarships,
  Announcements, Data Bank, Reports, Help. Student menu says *My Scholarship History*.
- [ ] **E18.** The login page "Demo accounts" box lists Wena's account. Wena's profile shows *Sex: Female*.

**E-mail with Gmail (optional, needs internet)**
- [ ] **E19.** Set the Gmail values from `backend/.env.example` (App Password, `MAIL_ALWAYS_TO=wenarose.contiga@carsu.edu.ph`),
  run `php artisan config:clear`, restart `php artisan serve`. As Wena, apply to an open program, upload the files,
  submit. As staff: Complete → Approved by agency. The e-mail arrives in Wena's CSU inbox (check Spam too) and
  Reports → *E-mails sent* shows SENT.

## Part F — Round 8 features

Run `php artisan migrate:fresh --seed` first. Admin: `admin@carsu.edu.ph` / `Admin@12345`.

**System Admin**
- [ ] **F1.** Log in as admin → the Admin Dashboard opens. Menu: Dashboard, Manage Staff, Manage Students, Activity
  Logs, System Settings, All Scholarships, Reports, Help. Typing `/staff/applications` in the address bar goes back
  to the admin dashboard (the admin does not process applications).
- [ ] **F2.** Manage Staff → *New staff account* → a temporary password is shown once. The row shows *Temporary password*.
- [ ] **F3.** Edit the new account → change the role to System Admin → saved. Your own row has no Deactivate button
  and you cannot change your own role.
- [ ] **F4.** Deactivate the new account → it cannot log in ("This account is deactivated"). Reactivate → it can.
- [ ] **F5.** Reset password → a new temporary password; the user's open sessions are signed out.
- [ ] **F6.** Manage Students → search "contiga" → Wena. *New student account* with an existing Student ID → error.
- [ ] **F7.** Log in with a temporary password → the *Set your own password* page opens and no other page can be
  opened until the password is changed. The button stays grey until the three rules are ticked.
- [ ] **F8.** System Settings → set school year 2026-2027, semester, OAS e-mail and phone → Save. The footer of every
  page and the student's Contact OAS page show them; Payroll uses the term as the default period.
- [ ] **F9.** Activity Logs → newest first; filter *Account changes*; Export CSV. The demo history already contains the
  staff steps from the seeded applications.

**Contact OAS**
- [ ] **F10.** As Wena: Contact OAS → choose a topic, subject and message → Send. It appears under *My messages* as
  *Waiting for OAS*.
- [ ] **F11.** As staff: Dashboard → *Student Messages* card → reply. As Wena: the bell shows "OAS replied…";
  Contact OAS shows the reply.

**Enrollment (Registrar list)**
- [ ] **F12.** Staff → Enrollment shows the demo Registrar list. *Verify All Enrollments* → Enrolled / Not on the
  list / Needs manual check. *Record all as enrolled* → those students appear in Scholar Records → Ready to Tag.
- [ ] **F13.** *Not on the list* → select → *Record selected as NOT enrolled* asks for confirmation first.
- [ ] **F14.** Choose *Active grantees* → Verify All → grantees not on the list can be recorded as not enrolled
  (they are then skipped by Payroll).
- [ ] **F15.** *Upload a newer list* with a CSV whose columns are "Student No, Surname, Given Name, Program" → the
  columns are recognised → Save list.

**Auto-Review and Forwarded to Agency**
- [ ] **F16.** Applications → *Auto-Review submitted applications* → three numbers: Ready to forward, Needs manual
  review, Probably incomplete. Nothing changed yet.
- [ ] **F17.** Ready → tick two → *Forward selected to the agency* → they leave the list and appear in
  *Forwarded to Agency* with today's date.
- [ ] **F18.** Probably incomplete → each row has a pre-written remark (editable) → tick → *Send selected back*.
  The student sees the remark (Needs action).
- [ ] **F19.** Needs manual review → *Open review* opens that application's review window.
- [ ] **F20.** Forwarded to Agency → program buttons with counts, longest waiting first (over 30 days in orange),
  *Export CSV* for one program.

---

## Part G — Round 9: announcement pictures

- [ ] **G1.** As Wena: the dashboard shows **Latest Announcements**: three cards with picture, date, title, a short
  preview and *Read more*.
- [ ] **G2.** *Read more* opens the announcement page with the full-size picture and the whole message. An expired
  announcement (e.g. `/student/announcements/8`) says it is no longer available.
- [ ] **G3.** As staff: Announcements shows a thumbnail for each one (8 seeded; 2 expired, shown faded).
- [ ] **G4.** New announcement → choose a .txt file → "Choose a JPG, PNG or WebP picture." A picture over 2 MB →
  "larger than 2 MB". A good JPG → preview → Post. The new announcement shows the picture.
- [ ] **G5.** Edit it → *Remove picture* → Save → the CSU placeholder is shown and the file is deleted from
  `backend/storage/app/public/announcements/`.

---

## Part H — Round 10: OAS programs, program tabs, auto-refresh

- [ ] **H1.** Footer: OAS name, address, oas@carsu.edu.ph and 0960 835 4606; no semester text.
- [ ] **H2.** As Wena → Scholarships: filters *CHED-funded, Other Government, Private-funded, University-funded*;
  6 program cards with Apply, then one compact list **Apply directly at the agency** (7 programs). Details on
  one of them explains how to apply at the agency (no Apply button).
- [ ] **H3.** Contact OAS shows the OAS description, Prof. Sheila Rae E. Permanes (Unit Head), e-mail, phone and the
  Facebook page.
- [ ] **H4.** Staff → Applications: a row of **program tabs** (like Excel sheets) with counts; *All programs* stays
  visible. Click *TES* → only TES, sorted A–Z by last name, and the Program column disappears. The status dropdown
  shows counts.
- [ ] **H5.** Auto-refresh: keep Applications open; in another window change an application (or run Auto-Review →
  Forward). Within about 20 seconds the list updates by itself ("updated" time changes).
- [ ] **H6.** The same program tabs, A–Z, on Scholar Records, Payroll (payroll list), Forwarded to Agency and
  Auto-Review.
- [ ] **H7.** Payroll → Preview: grantees of agency-direct programs (e.g. DOST) are *Skipped: Paid directly by the
  agency*.
- [ ] **H8.** Approved Lists: upload `approved-list-sa.csv` (from `sample-approved-lists.zip`, or run
  `php artisan demo:agency-lists` after your own testing) for *Student Assistantship (SA)* → "Matched 6, 1 unmatched,
  0 errors". `approved-list-dost.csv` for *DOST* → 3 grantees recorded.
- [ ] **H9.** Staff → Scholarships → create a program with *How students apply: Agency-direct* → no document picker;
  the table says *Apply at the agency*.

---

## What has and has not been tested

Part H (round 10) was tested by Claude on **10 Oct 2026** in the browser (H1–H9; the staff pages also at 390×844),
and every sample approved list was processed through the API with 0 errors.

Part G (round 9) was tested by Claude on **10 Oct 2026** in the browser (G1–G5 at 1366×900, the dashboard also at
390×844), and Part E was run again afterwards with the same results.

Part F (round 8) was tested by Claude on **10 Oct 2026**: F1–F20 in the browser (1366×900; the new pages also at
390×844 with no sideways scrolling), every new API rule (staff and students get 403 on admin pages, the admin gets
403 on review and payroll, deactivated accounts get 401 and cannot log in, last-admin and self-protection rules),
`php artisan test` (2 passed), and the new migrations rolled back and re-run on SQLite.

Part E (round 7) was tested by Claude on **10 Oct 2026**: E1–E18 in the browser (1366×900 and 390×844, no page
scrolls sideways on a phone), plus the API rules (draft/submitted cannot be approved, needs-action needs remarks,
an application with a missing required document cannot be forwarded, payroll is never added twice for the same
period, students cannot open staff pages), `php artisan test` (2 passed), and the new migrations rolled back and
re-run. **E19 (real Gmail) was not tested** — Claude's environment cannot reach Gmail.

Part D (round 6) was tested by Claude on **7 Oct 2026** the same way (D1–D13 in the browser at 1366×900 and
390×844, e-mails with MAIL_MAILER=log; a failing SMTP server was also tried: the approval still works and the
e-mail is logged as FAILED). Parts B and C were re-run afterwards with the same results.

Part C (round 5) was tested by Claude on **6 Oct 2026** in the same way: C1–C13 in the browser, plus the earlier
staff workflow again (AI check, decision, verify, tag, batch payroll, Data Bank, CSV) with unchanged results.

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
