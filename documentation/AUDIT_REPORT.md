# CSU UniScholar (ScholarGuide) — System Audit Report

**Date:** October 25, 2026 · **Audited code:** GitHub `wena-wenxx/CSU-Unischolar`, branch `main`, commit `1c38f53`
("Round 14"), plus the changes listed in section J (not committed; delivered as a zip for the developers to apply).
**Data used:** fictional seed data only (131 fictional students, 20 sample programs). No real student records.
**Defense:** November 5, 2026.

How to read this report: every claim says how it was checked.
**Automated test** = a test in the repository that anyone can re-run (`php artisan test`, `pytest`).
**Browser run** = a scripted run of the real website (React + Laravel + the AI service) in a browser.
**Code reading** = checked by reading the code only (not executed).
**Not tested** = said plainly.

---

## A. Executive summary

**Overall:** the system is **ready for adviser testing and for a demonstration with fictional data**. It is **ready
for panel evaluation after the four items in section I.1** (re-run the tests on the developers' PC with the real
OCR libraries, have OAS confirm the real eligibility criteria, decide two business rules, rehearse the demo).

**Verified working (automated tests + browser runs):** the whole OAS workflow from application to payroll
(apply → submit → AI document check → OAS review → forward → agency decision / approved list → enrollment check →
grantee tagging → payroll → General Form 6-A as PDF and Word); permissions for all four roles on every API address;
the eligibility score against hand-calculated cases; Word/Excel/CSV list reading; the outside-scholarship list
import with "to check" rows; private document storage.

**Fixed in this audit:** 4 critical (P0), 6 high (P1), 9 medium (P2) and several low (P3) issues — section B.

**Implemented but not fully tested here:** PaddleOCR on scanned images and the real RapidFuzz library (the audit
workspace could not install them; the same formula was used — section E.3); Gmail sending (no mailbox in the
audit workspace); the Ollama memo reader with a real model (tested with a stand-in that answers like Ollama).

**Critical risks remaining:** the sample eligibility rules are **not** official criteria (now labelled on screen);
there is no connection to the Registrar or CHED, so student data is only as good as what is typed in and checked by
staff; deployment to the internet is not done and not tested.

---

## B. Findings and fixes (most serious first)

| # | Pri | Issue | Evidence (how found) | Root cause | Fix applied | Test | Result | Remaining risk |
|---|---|---|---|---|---|---|---|---|
| 1 | **P0** | Student documents (IDs, ITRs, birth certificates) could be opened by **anyone with the link, without logging in** | Browser: `GET /storage/documents/demo/2026-00002/...pdf` → 200 while logged out | Files saved on the **public** disk (`storage/app/public`) | Files saved on the **private** disk; opened only through a **signed link that expires after 30 minutes**, given only to the owner and to OAS staff. Migration `2026_10_25_000002` + command `documents:make-private` move old files out of the public folder | `DocumentPrivacyTest` (2) | Pass; old public link now 403 | A link copied and shared within 30 minutes still opens |
| 2 | **P0** | **Anyone could create a student account** with any Student ID by calling `POST /api/register` (no page uses it; admins create accounts) | Code reading + route list | Leftover route from early rounds | Route removed (controller kept, not routed) | `RoleBoundaryTest::test_nobody_can_sign_themselves_up` | Pass (404) | — |
| 3 | **P0** | A **processed** payroll entry (stipend released) could still have its **amount, period or status changed**; Draft could jump to Processed; nothing logged | Code reading of `PayrollController::update` | The single-entry edit skipped the rules the bulk buttons use | Same steps as the buttons (Draft → Ready → Processed, Ready → Draft); processed = final; amount/period only while Draft; every change logged with old → new | `WorkflowTest::test_a_payroll_entry_follows_the_steps…` | Pass | — |
| 4 | **P0** | Unlimited **password guessing** on sign-in | Route list: no rate limit on `/api/login` | Missing throttle | 10 tries a minute per e-mail and computer, then a clear message | `RoleBoundaryTest::test_sign_in_is_limited…` | Pass | — |
| 5 | P1 | A **temporary password** was forced to change only by the website; the API could be used without changing it | Code reading (`must_change_password` only in React) | Not enforced on the server | Middleware `RequirePasswordChange`: only *who am I*, *change password*, *sign out* allowed until changed | `RoleBoundaryTest::test_a_temporary_password…` + browser run (S1–S3) | Pass | — |
| 6 | P1 | The **System Admin could upload documents into a student's application** (against "admin never does OAS work", ADMIN_ROLE.md) | Automated probe of every route as admin | Upload check only looked at "is student" | Admin refused (403) | `RoleBoundaryTest` (admin tests, with real records) | Pass | — |
| 7 | P1 | **Re-activating an old grant** ignored the one-active-scholarship rule | Code reading of `ScholarRecordController::update` | Rule only on tagging | Same check on re-activation | `WorkflowTest::test_re_activating_an_old_grant…` | Pass | — |
| 8 | P1 | The database did not stop **duplicate scholar records** or **two payroll entries for one period** (only the code did; two quick clicks could slip through) | Migration reading | No unique indexes | Unique indexes (migration `2026_10_25_000003`; stops with a clear list if old data already has duplicates; nothing deleted automatically); friendly message on a collision | `WorkflowTest` (DB refuses a second record; payroll twice) | Pass | — |
| 9 | P1 | An **outside scholarship from a past school year** still counted as "holds another scholarship" | Code reading (adviser review issue 4) | Only `status = active` was checked | Lines from a past school year do not count; the list shows "Past school year: not counted" | `EligibilityEngineTest::test_an_outside_scholarship_from_a_past_school_year…` | Pass | Lines with **no** school year still count until staff mark them ended (on purpose) |
| 10 | P1 | **Score looked like a chance of approval**; a GWA only typed by the student counted the same as a checked one ("100%" next to "not checked") | User report + code reading | Pass/fail checks with equal weight | Points: verified data 1, student-typed ½, AI-checked document = AI name-match certainty, staff-verified 1; label "Requirements match", "Rules x/y · Docs x/y" shown separately; "Based on what the student typed" tag | `EligibilityEngineTest` incl. hand-calculated cases | Pass | See section D limits |
| 11 | P2 | Staff could not **correct** a wrong GWA/income typed by a student, only mark it checked | Adviser panel question | No input for it | "The documents show different figures?" in the Why? window; old → new saved in the Activity Log; scores recalculated | `EligibilityEngineTest::test_staff_correct_a_wrong_gwa…` | Pass | — |
| 12 | P2 | Rule changes were logged as field names only; **no source** for the rules | Code reading | — | Old → new values in the log; who/when kept; "Where these rules come from" field (memo reader fills it with the memo's sentences); shown in the Why? window. Sample rules say they are **not official** | `EligibilityEngineTest::test_rule_changes_keep_who…` | Pass | Rules have no version history beyond the log |
| 13 | P2 | **Word (.docx) and Excel (.xlsx) lists failed** on the developers' PC | User report | Reading depended on the AI service and its libraries | Laravel reads .docx/.xlsx itself (`OfficeTable`); the AI service has a built-in backup reader | `OfficeTableTest` (4), `test_office_builtin.py` (3); compared with openpyxl on 106 Excel-made files: all cells equal | Pass | Old .doc/.xls not read (clear message) |
| 14 | P2 | Student IDs that Excel turned into numbers (202600001) did not match | Browser run | Exact text match | IDs compared without dashes/spaces | `WorkflowTest::test_an_approved_list…` + browser run | Pass | — |
| 15 | P2 | Dashboard told students to "press Check with AI" on files already sent to OAS (no button there) | Browser run | To-do ignored the application status | Says "sent to OAS with your application: OAS will check them" | Browser run | Pass | — |
| 16 | P2 | E-mail failure said only "could not be sent" | User report | Raw error hidden | Plain reason + what to change in `.env` (App Password, MAIL_SCHEME, host) in the message and in Reports | `EmailErrorTest` | Pass | Real Gmail not tested here |
| 17 | P2 | AI service error text (server names) was sent to **students** | Code reading | — | Technical detail only for staff | `WorkflowTest::test_when_the_ai_service_is_off…` | Pass | — |
| 18 | P2 | A document that passed with a note ("name similar but not exact") showed students only "no issues" | Edge-case run (E.3) | Notes hidden when status = validated | Note shown under the result | Browser check | Pass | — |
| 19 | P2 | Wording said a passed document had "no problems" (sounds like proof it is genuine) | Adviser review issue 2 | — | "The AI check found no issues (OAS staff still review it)" | Code reading | Done | — |
| 20 | P3 | Applications list long and repetitive; program tabs hidden off-screen; welcome guide repeated; empty strip on wide screens; long checklist lines; grammar | User report + browser runs | Layout | Grouped by student; tabs wrap; guide once; full width; "Show list"; fixed | Browser screenshots | Done | — |

**Checked and found correct (no change needed):** application status steps are enforced on the server
(`Application::STAFF_NEXT`); an incomplete application cannot be forwarded; tagging needs *approved* + *enrollment
verified* + no other active scholarship; payroll takes only active, enrolled grantees and skips anyone already in
the period; the AI service being off sends a document to **manual review** (never "passed"); deactivating an account
signs it out everywhere; uncertain list matches must be resolved by staff before saving; AI Recommendations never
create applications or approvals by themselves (`Round12ExtrasTest`, `EligibilityEngineTest`).

---

## C. First-time user reports

### C.1 Student (browser run `persona.cjs`, new fictional account "Liza Ocampo")

| Step | Expected | Actual |
|---|---|---|
| Admin creates the account | Temporary password shown once | ✓ shown once |
| First sign-in | Must set own password; cannot skip | ✓ sent to "Set your own password"; typing the dashboard address sends back |
| Change password | Dashboard opens | ✓ "Password changed." |
| Upload Valid ID in My Documents | Saved | ✓ "Valid ID saved." |
| Check with AI (test file with almost no text) | Not passed; manual review | ✓ "The file could not be read automatically. Please check it manually." |
| Apply to a program | Draft created, list of required documents | ✓ draft; "Use my saved documents" offered |
| Submit with documents missing | Not possible, explained | ✓ Submit disabled; banner says to upload each required document |
| Sign out, open a student page | Login page | ✓ |
| Admin deactivates the account while the student is signed in | Next page → login; sign-in refused | ✓ "This account is deactivated. Please contact the OAS." |
| Another student's application/document (API) | Refused | ✓ 403 on view, submit, upload, replace, AI check (`RoleBoundaryTest`) |

Confusing parts found and fixed: items 15, 18, 20 above. Still worth knowing: the student cannot re-run the AI check
on a file already sent to OAS (by design; OAS checks it).

### C.2 OAS staff (browser runs `flow.cjs`, `staffp.cjs`, `al2.cjs`)

| Step | Actual |
|---|---|
| Review → AI check (5 documents) → forward → agency approved → verify enrollment → tag → payroll preview/confirm | ✓ all steps, plain-language messages, no errors |
| Approved list (Excel with a dash-less ID, and Word) **with the AI service off** | ✓ read; IDs matched; summary per row (new approved / already approved / unmatched) |
| Outside scholarship list (Word, `foundation-scholars.docx`) | ✓ 2 matched, 1 "to check" (staff must choose the student) |
| Registrar list (CSV, 60 rows) + Verify All | ✓ saved; 5 enrolled matches suggested; nothing saved until "Record" |
| Reports | ✓ 6 sections shown |
| General Form 6-A for CMSP, one period | ✓ PDF and Word downloaded (real dompdf) |
| Without choosing one period | ✓ explained: "Choose one period… one form is for one period" |

The crawler (`crawl.cjs`: 4 accounts, ~234 safe clicks, 111 links) reported one problem, "export menu did not
open" on Forwarded to Agency. Re-checked by hand (`em.cjs`): the menu opens on one click and the Excel file
downloads — a **false alarm** of the crawler (an earlier click left the menu open).

### C.3 System administrator

| Check | Actual |
|---|---|
| Create account, temporary password, forced change | ✓ (browser run) |
| Deactivate → signed out everywhere, cannot sign in | ✓ (browser run) |
| Activity Log shows the admin's actions | ✓ (browser run) |
| Admin cannot do OAS work through the API (review, forward, tag, payroll, upload into applications, AI check) | ✓ `RoleBoundaryTest` — after fix 6 |
| Admin **can** read reports and edit scholarship programs | ✓ by design (ADMIN_ROLE.md) |
| Staff cannot use admin addresses; students cannot use staff/admin addresses; logged-out = 401 everywhere except login, office contact and signed file links | ✓ `RoleBoundaryTest` (every API route) |

---

## D. AI and eligibility audit

### D.1 What the AI actually does

| Part | Technology | What it does | What it does **not** do |
|---|---|---|---|
| Document check | PyMuPDF (text PDFs), PaddleOCR (scans/photos), RapidFuzz | Reads the text; checks the name (fuzzy), the Student ID, and keywords of the requested document type; flags problems | Prove a document is genuine; detect a well-made fake with the right name |
| List reading | Laravel (`OfficeTable`) for .docx/.xlsx; AI service for PDF/photos | Turns a list into rows; staff choose the Student ID column | Guess which column is the ID without staff confirmation |
| Outside-scholarship matching | RapidFuzz + Student ID | ID match, name match, "to check" when two names are close or ID and name disagree; duplicates flagged | Save uncertain rows without a staff choice |
| Requirements match (AI Recommendations) | Rules engine (Laravel) | Checks each program's rules and documents for every active student account | Predict approval; approve anything |
| Field fit | fastembed (BAAI/bge-small-en-v1.5) or TF-IDF | Ranks programs by the student's field; explains | Change eligibility |
| Memo reader | Ollama (qwen2.5:1.5b / llama3.2:3b) or patterns | Suggests rules with the sentence they came from; values without a found sentence are marked "check" | Save anything — staff tick, put in the form, and press Save |

### D.2 The formula (exactly as coded in `EligibilityEngine`)

**Requirements match = points earned ÷ number of checks × 100** (rounded).

| Check | Must-have? | Points when met |
|---|---|---|
| GWA ≤ program's highest GWA (**inclusive**: 2.00 meets "2.00 or better") | yes | 1 if OAS verified; ½ if only typed by the student |
| Family income ≤ ceiling (**inclusive**) | yes | 1 if verified; ½ if typed |
| Year level in the list | yes | 1 (from the student's account; see D.4) |
| College in the list | yes | 1 (from the student's account) |
| No other active scholarship (when the program says so) | yes | 1 |
| Each required document | no | staff-verified 1; AI-passed = name-match certainty (e.g. 0.92); not uploaded / flagged / expired / not checked 0 |

**Verdict** is separate from the number: any must-have **not met** → *Fails a rule* (never recommended, whatever the
score); any must-have **unknown** (e.g. no GWA) → *Not enough data* (not a fail); otherwise *Meets the rules*.
Students never see a percentage (it could be read as a chance); they see a checklist.

**Hand-calculated cases** (program: GWA 2.00 or better, income ≤ ₱200,000, one scholarship only, 1 document; all in
`EligibilityEngineTest::test_hand_calculated_cases`):

| Student | Data | Hand calculation | Expected | System |
|---|---|---|---|---|
| A | GWA 2.00, income 200,000 (both exactly at the limit), verified; COR AI-passed (certainty 100) | 1 + 1 + 1 + 1 = 4 / 4 | 100%, meets | 100%, eligible ✓ |
| B | GWA 2.01, verified | 0 + 1 + 1 + 0 = 2 / 4 | 50%, fails a rule | 50%, not_eligible ✓ |
| C | typed by student; COR flagged | ½ + ½ + 1 + 0 = 2 / 4 | 50%, meets | 50%, eligible ✓ |
| D | no GWA; income typed | 0 + ½ + 1 + 0 = 1.5 / 4 | 38% (37.5), not enough data | 38%, no_data ✓ |

### D.3 Where the criteria come from

| Source | In the system | Status |
|---|---|---|
| Official provider criteria | **Not loaded.** The 6 seeded programs use **SAMPLE** rules; the Why? window now says "SAMPLE rules… NOT the official criteria" | OAS must enter the real ones |
| Staff-configured rules | Scholarships → Manage → Eligibility rules; who/when and old → new are logged | Working (automated test) |
| AI-extracted draft rules | Memo reader suggestions; never saved without staff pressing Save; the memo sentences go into "Where these rules come from" | Working (stand-in Ollama) |
| "Who it is for" sentences of DOST, DA-ACEF, ACEF-GIAHEP, Landbank, NGCP | Seeder, with the source web pages in comments | From public pages; re-check before the defense |
| Student-entered data | GWA, income (verified or not, shown); year level, college (typed at sign-up; corrections need an OAS-approved request) | Shown honestly |
| Verified enrollment | Registrar list upload + Verify, before tagging | Working (browser run) |

### D.4 Limits to say out loud
- Year level and college are **not** checked against the Registrar for the score (no connection); enrollment is
  checked later, before tagging.
- The name-match certainty measures "is the name on the document the student's", not "is the document genuine".
- Exceptions in real memos (e.g. "or a GWA of 2.25 for indigenous peoples") are not modelled; staff handle them.
- The field fit and memo reader are only as good as the text given to them; both are suggestions.

### D.5 Outside-scholarship matching (tested in `test_list_reader.py`, 17 tests)
ID + name agree → matched; ID found but name looks different → **to check**; unknown ID but name matches → **to
check**; two almost-equal names → **to check** ("similar name"); same student listed twice → second row **to
check**; nobody close → unmatched. Staff must choose the student for every "to check" row before saving
(`ExternalGranteesPage`), re-uploads do not create a second active record for the same program, and lines from a
past school year do not block anyone (fix 9).

---

## E. Test evidence

### E.1 Automated — Laravel (`cd backend; php artisan test`)
**56 tests, 422 assertions, all passed** on October 25, 2026 (SQLite, fictional data). New in this audit:
`RoleBoundaryTest` (9), `WorkflowTest` (12), `DocumentPrivacyTest` (2), `OfficeTableTest` (4), `EmailErrorTest` (1),
and 5 more in `EligibilityEngineTest` (23 tests before the audit + 33 new = 56). To make sure the tests can fail, one rule was broken on purpose (the
enrollment check before tagging): 2 tests failed as expected, and passed again after the rule was restored.

### E.2 Automated — AI service (`cd ai-service; python -m pytest`)
In the audit workspace: **34 passed**, 1 failed, `test_validation.py` not run.
- The 1 failure (`test_digital_pdf_table`) is the workspace's PyMuPDF **stand-in**, not the code (it passed on the
  developers' PC in round 12). **Re-run on the PC.**
- `test_validation.py` needs FastAPI's test client, which could not be installed in the audit workspace (no
  internet for Python packages). **Re-run on the PC.**

### E.3 Document-check edge cases (`validation.validate_text`, fictional texts; RapidFuzz **formula** re-implemented
because the library could not be installed — re-run on the PC to confirm the numbers)

| Case | Result | Website status |
|---|---|---|
| Exact name | name 100%, no flag | validated |
| "Juan L. Navarro" (reordered, middle initial) | 87.5%, note "similar but not exact" | validated with a note (counts 0.875) |
| One letter wrong ("NAVARO") | 97.1%, no flag | validated |
| Different person | 41.2%, "name not found" | **flagged** |
| Student ID missing on a COR | "Student ID number was not found" | **flagged** |
| Barangay Clearance uploaded as COR | "does not look like the requested document" | **flagged** |
| Blank / unreadable | "Very little text could be read" | **needs review** |
| OCR noise ("NAVARR0") | 88.9%, note | validated with a note |

**Not tested here:** real scans with PaddleOCR (rotation, blur, low resolution), password-protected or corrupted PDFs.
Test them on the PC with `csu-unischolar-sample-files.zip` → `problem-files`.

### E.4 Browser runs (real React + Laravel + AI stand-in; scripts in the audit workspace)
| Run | Result |
|---|---|
| Full workflow, 12 steps (`flow.cjs`) | All passed, no errors |
| Crawler, 4 accounts (`crawl.cjs`) | 1 report → confirmed false alarm (C.2) |
| Student persona (`persona.cjs`) | All checks as expected (C.1) |
| Staff persona (`staffp.cjs`) | All checks as expected (C.2) |
| Memo reader with a stand-in Ollama (`memo2.cjs`) | "Read by the local AI model (qwen2.5:1.5b)", 3 rules with sentences |

### E.5 Blocked / not tested
Real Gmail sending · real PaddleOCR · real Ollama model · MySQL (tests use SQLite; the app runs on MySQL on the PC)
· internet deployment. (Phone layout: 9 main staff and student pages checked at 390 px wide — no sideways
scrolling of the page; wide tables scroll inside their box.)

---

## F. UI/UX changes in this audit
Sidebar profile like CSU MySchool (big photo or plain person shape, name in capitals, "ID | Course - Year") · one
name only (top bar duplicate removed) · full-width pages · program tabs wrap · Applications grouped by student ·
student checklist with "Show list" · welcome guide once · "Requirements match" label with Rules/Docs split ·
"Based on what the student typed" tag · points column and rules source in the Why? window · correct-figures form ·
"Past school year: not counted" tag · plain e-mail error reasons in Reports · document notes for students.

---

## G. Panel questions — short answers with evidence

| Question | Short answer | Evidence | Limit to admit |
|---|---|---|---|
| Why not keep Google Forms + Excel? | One place for applications, documents, checks, lists, enrollment, grantees and payroll; less re-typing between steps; every decision has a name and time | Workflow run C.2; Activity Log | Agencies still send their own lists |
| Which decisions are OAS's and which the agency's? | OAS checks documents, forwards, verifies enrollment, tags, prepares payroll. The **agency** approves (recorded by OAS or by uploading its list) | `Application::STAFF_NEXT`; approved list test | — |
| What exactly does the AI do? | Reads documents and lists, flags problems, suggests matches and rules. It never approves | Section D.1 | Not fraud detection |
| Why PaddleOCR and RapidFuzz? | Free, run offline on the OAS computer (student data stays local), PaddleOCR reads scans/photos, RapidFuzz handles misspelled/reordered names fast | `ai-service/` | OCR accuracy depends on scan quality |
| How is the percentage calculated? Is 85% an 85% chance? | Points ÷ checks (D.2). **No** — it is a requirements match, not a chance; the agency decides | `EligibilityEngineTest` hand cases | — |
| Where do the criteria come from? | Entered by OAS from the memo (or suggested by the memo reader with the sentence) and saved by staff; source and who/when kept | D.3 | Demo uses **sample** rules |
| Student typed GWA 1.50 but the grades say 2.75? | Typed data counts half and is tagged; staff correct it from the document, old → new is logged, scores update | `test_staff_correct_a_wrong_gwa…` | Depends on staff checking |
| Fake certificate with the right name? | The AI cannot detect that; it only says "no issues found". Staff verify (e.g. PhilSys Check) and mark "verified" | "How to check it is genuine" in the review window | Stated in Help |
| Two students named Juan Dela Cruz? | ID first; close names go to "to check" and staff must choose | D.5 | Lists without IDs need more staff time |
| Expired outside scholarship? | Past school year does not count; staff can mark lines ended | fix 9 | Lines with no school year count until ended |
| Can the score override a failed rule? | No — verdict "Fails a rule", not recommended | hand case B | — |
| How is enrollment verified? | Registrar's list upload + Verify All (suggestions) → staff press Record | C.2 | No live Registrar connection |
| Who can tag a grantee? | OAS staff only, after approval + enrollment, one active scholarship | `WorkflowTest`, `RoleBoundaryTest` | — |
| Does it transfer money? | No. Payroll lists and Form 6-A only; "Processed" is a status | Payroll page text | — |
| Privacy safeguards? | Private documents with expiring links, role checks on the server, login limit, forced password change, Activity Log, fictional demo data | Section B 1–6 | Shared links work for 30 min |
| AI service off? | Documents go to manual review; Word/Excel lists still read; the rest works | `WorkflowTest`, browser | PDF/photo lists need it |
| How did you test? | 56 Laravel tests, 34 AI tests, browser runs, hand calculations | Section E | Not tested by OAS staff yet |

---

## H. Setup and demonstration

**Apply this audit's changes** (PowerShell, `C:\Projects\CSU-Unischolar`): unzip the delivered zip over the project,
then:
```
cd backend
php artisan migrate               # adds: rules source, private documents, duplicate guards
php artisan test                  # expect 56 passed
cd ..\ai-service; .venv\Scripts\activate; python -m pytest   # expect all passed on the PC
```
`php artisan migrate` keeps your data. `php artisan migrate:fresh --seed` **erases** it and loads the fictional demo
data — use it only for a clean rehearsal, never on real records.

**Start:** Terminal 1 `cd backend; php artisan serve` · Terminal 2 `cd ai-service; .venv\Scripts\activate; uvicorn
main:app --port 8001` · Terminal 3 `cd frontend; npm run dev` · open the Ollama app (optional).

**Demo accounts (fictional, demo only):** staff `oas.staff@carsu.edu.ph` / `Staff@12345` · admin
`admin@carsu.edu.ph` / `Admin@12345` · students `student1…10@carsu.edu.ph` / `Student@12345`. Change them before any
real use.

**Demo script (about 15 minutes; shows a success and a failure at each important step)**
1. Admin: create a student → temporary password → the student must set their own (privacy, separation of duties).
2. Student (Juan): dashboard checklist → apply → "Use my saved documents" → submit.
3. Staff: review → **AI check** → show a flagged case (Maria's "Indigency" that is really a Barangay Clearance) →
   send back; forward Juan.
4. Staff: Approved Lists → upload the Excel sample (one ID without dashes still matches; one unknown ID stays unmatched).
5. Staff: Enrollment → Verify → Record; try to tag a student **not** verified → refused with the reason.
6. Staff: tag Juan → Payroll preview → confirm → press again (nobody added twice) → Form 6-A PDF.
7. Staff: AI Recommendations → Why? → points, "Rules/Docs", sample-rules warning → correct a wrong GWA.
8. Staff: External Grantees → upload the Word list → one "to check" row → choose the student.
9. Stop the AI service → check a document → "sent to manual review" (graceful failure).

---

## I. Remaining work and readiness

### I.1 Before the panel (must)
1. **Re-run all tests on the developers' PC** with the real PaddleOCR, RapidFuzz, PyMuPDF and FastAPI (E.2, E.3)
   and keep the output as evidence.
2. **OAS confirms the real criteria** for the programs shown in the demo, or the demo says clearly they are samples.
3. **Two business decisions** (not changed without you):
   - *One active scholarship:* today it is a hard rule for every program (agreed with OAS earlier). The adviser
     review asks whether it should follow each program's own rule. Decide and tell the panel which.
   - *Accounts:* only the admin creates accounts (public sign-up removed). Confirm this is how OAS wants it.
4. Rehearse the demo script twice on the PC that will be used.

### I.2 Should (if time allows)
Test real scans (rotated, blurred, low resolution, password-protected PDF) · test real Gmail sending ·
deployment rehearsal (see DEPLOYMENT.md: free hosting options changed) · version history for rules (today only the
log) · MySQL run of the test suite.

### I.3 Verdict
- **Ready for demonstration (fictional data):** yes — evidence: E.1, E.4.
- **Ready for adviser testing:** yes.
- **Ready for panel evaluation:** after I.1.
- **Not ready for real OAS use:** no institutional approval, real criteria, deployment, security review by CSU ICT,
  or data-privacy clearance yet.

---

## J. Change summary
Not committed or pushed (developers apply and commit). 58 files changed since GitHub `main` `1c38f53`:

- **New migrations:** `2026_10_25_000001` rules source, `…000002` move documents to private storage,
  `…000003` duplicate guards.
- **New backend:** `OfficeTable` (Word/Excel reader), `DocumentFileController` (signed file links),
  `RequirePasswordChange` middleware, `documents:make-private` command.
- **Changed backend:** AIController, AgencyListController, ApplicationController, DataBankController,
  ExternalGranteeController, PayrollController, RecommendationController, ReportController, ReviewQueueController,
  ScholarRecordController, ScholarshipController, StudentController, StudentDocumentController; models Document,
  EmailLog, ExternalGrantee, Scholarship; EligibilityEngine, NextSteps, AppServiceProvider, routes/api.php; seeders.
- **New tests:** RoleBoundaryTest, WorkflowTest, DocumentPrivacyTest, OfficeTableTest, EmailErrorTest,
  `ai-service/tests/test_office_builtin.py`.
- **AI service:** `list_reader.py` (built-in Word/Excel reader).
- **Frontend:** EligibilityRules, MatchesCard, MemoReader, SheetTabs, WelcomeModal, index.css, format.js,
  listFile.js, and the pages AgencyLists, Enrollment, ExternalGrantees, Recommendations, StaffApplications,
  StaffReports, StaffScholarships, ApplicationDetail, MyDocuments.
- **Docs:** this report, AI_RECOMMENDATIONS.md, TEST_PLAN.md.
- **Dependencies:** none added. `composer.json` (with your dompdf) is not touched.
- **Configuration:** none required. For deployment keep `APP_DEBUG=false` and set `FRONTEND_URL`.
