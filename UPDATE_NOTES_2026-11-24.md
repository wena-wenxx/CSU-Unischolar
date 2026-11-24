# Update notes, Nov 24, 2026 (Round 26): slow system, AI checks stuck on "Checking..."

Starting point: `main` at `e3e1be6`. Nothing was committed or pushed.

## Root causes found in the code and your logs

| # | What you saw | Cause | Where |
|---|---|---|---|
| 1 | Pages stuck loading; simple requests (`/api/settings/public`) took 8–16 s; "SQLSTATE[HY000] [2002] … actively refused" | **MySQL (XAMPP) had stopped.** On Windows each failed connection attempt to a closed port takes about 2 s and Laravel retries, so every request waited 8–16 s and then failed. Signing out "fixed" it only because the page stopped asking. | XAMPP MySQL, not the code |
| 2 | Whole website slow while an AI check runs | `php artisan serve` on **Windows answers one request at a time** (`PHP_CLI_SERVER_WORKERS` is not supported on Windows). A 1–3 minute AI check made every other page wait. | `AIController@validateDocument` waited for the AI inside the web request |
| 3 | "Checking..." forever; `Maximum execution time of 60 seconds exceeded` | The document was set to `processing` **before** calling the AI. Laravel waited up to 180 s, but PHP stops a request after 60 s, so nothing ever changed the status back. | `AIController.php` (`Http::timeout(180)`) |
| 4 | Starting a check on a 2nd student reset the 1st button | One shared "busy" id per page (`busyDocumentId`, `checkingId`) | `StaffApplicationsPage.jsx`, `ApplicationDetailPage.jsx`, `MyDocumentsPage.jsx` |
| 5 | AI service slow; requests queue up | The OCR ran inside an `async` endpoint, which **blocks the whole FastAPI service** until it finishes; PaddleOCR loaded on the first check; 4000-px phone photos read at full size; every page of a scanned PDF read | `ai-service/main.py`, `ocr_engine.py` |
| 6 | Student sees "Being checked by OAS" instead of the AI result | Every status other than uploaded/validated was labelled "Being checked by OAS" | `lib/format.js` `studentDocumentState` |
| 7 | Search icon looks wrong | The "⌕" character shows as a small "o" in Windows fonts | `SearchBox.jsx` |
| 8 | Term dropdown empty | The term list comes from `/payroll/periods`; when that request failed (MySQL stopped) nothing filled the box | `EnrollmentPage.jsx` |
| 9 | Approved list: 9 of about 65 students | One likely cause (NOT confirmed without the file): scanned pages where no table lines were found were **skipped silently** | `ruled_table.read_generic` |

## What changed

**Backend (Laravel)**
- `app/Support/DocumentChecker.php` (new): the AI check in one place. Time limit below PHP's own limit when it runs in the web request; a check that never finished is released after 6 minutes; the reason a check failed is kept for the page.
- `app/Jobs/ValidateDocumentJob.php` (new): runs the check in the **AI worker** window.
- `AIController.php`: with the worker running, `POST /documents/{id}/validate` answers at once (202) and the worker does the check; pressing again while it runs does not start a second check; without the worker, the check runs in the request but can no longer stay on "processing". New `GET /documents/{id}/check-status` (running / done / failed + message).
- `AppServiceProvider.php`: the worker reports that it is running.
- `bootstrap/app.php`: when MySQL is down, every API call answers `503 "The database (MySQL) is not running. Start MySQL in the XAMPP Control Panel"` instead of a long SQL error.
- When the AI service is off, the document is no longer moved to "needs review" (it was not read at all); it stays "uploaded, not AI-checked" with the message "The AI service is not running".
- Tests: `tests/Feature/AiCheckBackgroundTest.php` (new, 5 tests); `WorkflowTest` updated for the new AI-off behaviour.

**AI service (Python)**
- Reading runs in worker threads (`run_in_threadpool`), so the service keeps answering while a file is read; PaddleOCR is used by one request at a time (lock).
- PaddleOCR loads when the service starts (in the background).
- Pictures larger than 2200 px are made smaller before OCR (`OCR_MAX_IMAGE_SIDE`).
- Only the first 4 **scanned** pages of a document are read for the AI check (`OCR_MAX_PAGES`), with a note to the student; typed pages are always read.
- The same file checked again for the same student answers at once (kept in memory, last 64).
- Every check logs how long it took; the answer includes `elapsed_seconds`.
- Optional lighter models: `OCR_DET_MODEL`, `OCR_REC_MODEL`, `OCR_DET_LIMIT` (NOT TESTED on the OAS files).
- Approved lists: pages where no table was found are now **reported by page number**; if most pages have no table lines, the file is read by word position instead. New test `tests/test_skipped_pages.py`.

**Website (React)**
- `lib/aiChecks.js` (new): one "Checking..." per document, follows checks in the worker, never waits more than 7 minutes.
- Student application page and My Documents: per-document state, and the AI findings shown under each document. New labels: "AI check running", "AI: no issues found", "AI: please look at this", "AI could not read it", "Uploaded, not AI-checked", "Verified by OAS".
- Staff review window: per-document state; reopening a student's window shows a check that is still running.
- Approved Lists: a reading panel (spinner, seconds, what is happening; no fake percentage); batch box reads "Use this program's open batch (or make a new one)"; "TES batch" → "Agency batch number".
- Enrollment: Term box never empty (fallback list) and asks "Choose the term of this list".
- Search icon drawn as a real magnifier.

**New file:** `start-csu.ps1` opens the 4 windows (backend, AI worker, AI service, website) after checking that MySQL runs.

## How to run (Windows)

1. XAMPP → Start **MySQL** (and keep it green).
2. In `C:\Projects\CSU-Unischolar`:
   ```powershell
   cd backend; php artisan config:clear; php artisan test; cd ..
   powershell -ExecutionPolicy Bypass -File .\start-csu.ps1
   ```
   Or by hand, 4 terminals: `php artisan serve` · `php artisan queue:work database --queue=ai --timeout=300 --tries=1 --sleep=1` · `uvicorn main:app --port 8001` (in `ai-service` with `.venv` active) · `npm run dev`.
3. No migration is needed (the `jobs` and `cache` tables already exist).

## `.env` (not changed by this update)

Your `.env` repeats several keys (`QUEUE_CONNECTION`, `MAIL_*`, `AI_SERVICE_URL`, `APP_FRONTEND_URL`); the **last** value wins. It works, but keep one of each. The AI worker uses the `database` queue on purpose, whatever `QUEUE_CONNECTION` says, so e-mails keep working as before. The `APP_KEY` was pasted in chat: it is a local key, but you can make a new one with `php artisan key:generate` (everyone gets signed out).

## Not done in this round (needs a decision or the file)

Upload-all button; graded invitation wording (high / partial / low match); staff activity feed for invitations; forwarded date per submission batch (needs a new table: migration to approve first); ZIP of supporting documents for an agency; separate "Upload file" and "Read file" steps; several open agency batches at once and a "Done processing" state; Verify Enrollments list (needs your data: it shows "approved applicants waiting: 0", so no approved application was found for that term); the 65-row list (send the file or a fictional copy); CSU photo background and wider UI review.

---

# Part 2 (same day): the nine follow-up items

## Database change (one new migration, adds only)

`2026_11_24_000001_create_agency_submissions_and_list_status.php`
- new table `agency_submissions`: one row each time OAS sends a list to an agency (date sent, program, batch, how it was sent, note, number of applications, who recorded it);
- new column `applications.agency_submission_id` (empty = not recorded as sent yet);
- new columns `agency_list_uploads.done_at`, `done_by`, `done_note` ("Done processing").
Nothing is changed or deleted; run `php artisan migrate` (never `migrate:fresh`).

## What was added

| Item | What it does now | Where |
|---|---|---|
| Upload all documents | Student picks all files at once; each is matched to a document by its file name (student can change it); files upload one after another, each with its own status (Uploading / Uploaded / Failed); the row buttons still work | `ApplicationDetailPage.jsx` |
| Invitation wording | High match (every rule and document met), Partial match (something missing or not verified), Low match (a rule not met). Each student's message lists what appears met, what is missing or unverified, and the next step, and always says it is not an approval. The single invite uses it; the bulk notice adds it under the staff message (tick box, on by default). The level shows in the AI Recommendations table | `app/Support/InvitationText.php`, `RecommendationController`, `RecommendationsPage.jsx` |
| Activity for invitations | Dashboard card "AI Recommendations and invitations": recalculations, invitations and notices sent, students who opened them, students who applied afterwards; totals sent/opened/applied. Built from saved records | `GET /staff/invitation-activity`, `StaffDashboard.jsx` |
| One forwarded date per sending | Forwarded to Agency: select students of one program → **Record as sent to agency** (date, how, note). Every student in that sending shows the same date; Excel/PDF exports use it. Students not yet recorded show "Not recorded as sent (ready since …)" | `AgencySubmissionController`, `ForwardedPage.jsx` |
| Supporting documents ZIP | Select students → **Supporting documents (ZIP)** → tick only the document types the agency asked for. One folder per student + `00_contents.csv` listing every file and every missing one. Staff only; written to the activity log | `POST /staff/forwarded/documents-zip` |
| Separate upload and reading | Approved Lists: choosing a file only shows it; **Read file** (green) starts reading; nothing is saved until Process | `AgencyListsPage.jsx` |
| Several lists / batches, Done processing | Each list keeps its own batch (several open batches per program were already allowed). After processing: **Upload another list**. Each list has **Done processing** (needs a note while rows are still open; changes no application; can be undone) and a Status column: Done / Needs attention / Processed, not marked done | `POST /staff/agency-lists/{id}/done` |
| Verify Enrollments | When nobody is listed, the page now says why, from real counts: waiting for the agency, approved but in a completed batch (reopen the batch), already verified. The button explains that it needs a Registrar list first | `EnrollmentListController@index`, `EnrollmentPage.jsx` |
| CSU photo / design | Already in place: the login page and the dashboard header use the CSU campus photo under a green layer; tables and forms stay on plain backgrounds (recommended, not changed). Search icon fixed in part 1 | — |

Tests: `AgencySubmissionTest` (6), `RecommendationNotificationTest` (+2). Backend total: 114 passed.

---

# Part 3 (same day): workflow improvements, staff actions, demo preparation

No new migration in this part (part 2's migration is still the only one).

| # | Request | Found before changing | Change made |
|---|---|---|---|
| 1 | Notified students leave AI Recommendations | Invited/notified students stayed in "Meets the rules" | They now appear only under **Previously notified / invited** (with "Notified on <date>"); nothing deleted; duplicates still need confirmation (unchanged) |
| 1 | AI check speed | Fixed in part 1 (background worker, cache of identical files, smaller pictures) | "AI check all" checks only files not checked yet |
| 2 | Manual verification | **Mark as verified** already existed (with who/when/how) | Verified files are not AI-checked again unless staff confirm; an old AI flag no longer sends the application to manual review in Auto-Review or counts as an AI flag in Applications; per-application overview (verified / no issue / flagged / unreadable / not checked) + **AI check all** |
| 3 | Remarks | Auto-Review suggested a remark only for missing documents | Each student's remark is written from **their own** flags (wrong document, name not found, unclear, old/expired, missing information, missing document); staff edit and send (**Send remarks to selected**) from both "Needs manual review" and "Probably incomplete"; in the review window **Suggest from AI flags** fills the Remarks box. Nothing is sent automatically |
| 4 | Set aside | One row at a time, free text | Common reasons + a reason suggested from what the system found (editable); **Set aside selected (same reason)**; **Open again** (undo, history kept) |
| 5 | Batch number / year | Only existing batches; "Automatic" | **+ New batch for this list…**: academic year, semester and agency batch number, name built as "Program - Semester - AY 2026-2027 - Batch 2" (year and batch number cannot be confused); duplicates refused by the server |
| 6 | Approved list results | Already: approved / new / already / unmatched / error / check, with Link and Set aside | Unchanged except set-aside improvements and Done processing (part 2) |
| 7 | Tag as grantee | Already: only after agency approval + enrollment verification, one active scholarship rule, tested | Unchanged |
| 8 | Completed applications | Already: "Still needs action" hides forwarded, verified and completed-batch applications; everything stays under All statuses, Data Bank and the student's history | Unchanged |
| 9 | Three accounts at once | **Bug**: the login was kept in localStorage, shared by all tabs, so a second login replaced the first and signing out in one tab signed out all | Each tab/window now keeps its own login (sessionStorage); tested: student, staff and admin tabs in one browser stay separate, and signing out the admin keeps the other two |
| 10 | Row numbers, A–Z | No numbers; Applications grouped by latest activity; Forwarded by days waiting | **No.** column on the main lists (follows search, filter, sort and Show more; never database ids); Applications grouped A–Z by surname; Forwarded A–Z. AI Recommendations keeps "highest score first" (it is a ranking) |
| 11 | Year / semester consistency | **Bug**: Approved Lists expected the current year and semester from `/settings/public`, which did not send them (boxes started empty); years and semesters were typed differently on several pages | `/settings/public` sends `current_school_year`, `current_semester`, `semesters`; one helper (`lib/terms.js`) for every page. A separate table is not needed: the values already come from Admin › Settings with the same format checks |
| 12 | Demo data and files | `demo:reset` (typed confirmation, demo students only), `demo:approved-lists`, `demo:external-lists`, `make_test_files.py` already existed | New `php artisan demo:test-files`: a 120-row list over 4 pages (PDF + Excel), a list with problems (duplicate, no ID, bad ID, other name, unknown ID, blank), a Registrar list with one student left out on purpose. Multi-page PDFs now supported by the sample-file writer |

Tests: `WorkflowImprovementsTest` (6 new). Backend total **120 passed**. Browser check here (stand-in AI, demo data): no page errors; three tabs stayed separate.

## Part 4 — Try the process again, staff "what to do next", fewer demo students, sample lists with Binag

No new migration. Nothing committed or pushed.

| Request | Change made |
|---|---|
| Reset Gleslimie so she can apply again | New `php artisan demo:reset-student` (type RESET). Default = the TEST_STUDENT_EMAIL student; or give a Student ID / e-mail of a **demo** student. Removes her applications (documents, AI results, history), grantee records/payroll and notifications; AI recommendation goes back to "new". Keeps her account, profile and messages. `--documents` also clears her document library. Only works with APP_ENV=local and DEMO_RESET_ALLOWED=true; real students are refused |
| Real-time staff notification | Staff bell checks every 10 seconds (and when the tab comes back) and shows a pop-up: who did what and **Next:** what to do. Bell has a "What to do next" list with a button that opens the right page (e.g. opens the review of that application). Covers: submitted / resubmitted applications, documents uploaded after "needs action", open student messages, profile change requests. An item leaves when it is handled (e.g. forwarded) |
| Fewer demo students | `.env`: `DEMO_BULK_STUDENTS=20` (default 120). Then `php artisan demo:reset --full` on the test database → 31 students (10 named + 20 bulk + Gleslimie) |
| Sample agency approved lists + enrolled list with Binag | `php artisan demo:test-files` now also writes `approved-<program>-with-binag.xlsx/.pdf` (currently forwarded students + Binag) for every active program, and the Registrar list always includes Binag (one demo student left out on purpose) |

Tests: `StaffAlertsAndStudentResetTest` (3 new). Backend total **123 passed**. Flow tested here (stand-in AI): reset → apply → staff alert → forward → approved list (Binag approved) → registrar list (Binag enrolled) → tag grantee → reset again → apply again. Staff bell pop-up was not tested in a real browser on Windows (NOT TESTED there).

## Part 5 — Upload all in one press, stay on Auto-Review, numbered list, start clean

No new migration. Nothing committed or pushed.

| Request | Change made |
|---|---|
| Upload all documents without pressing Upload on each row | When files are chosen on the rows, the top button becomes **Upload all chosen files (N)** and uploads them all in one press. With nothing chosen it still opens "Choose all your files at once". Row Upload buttons still work |
| Stay on Auto-Review after a review | **Open review** opens the review window on the Auto-Review page itself; closing it keeps the same program tab and group and counts the numbers again |
| Approved list test file with numbers | `demo:test-files` also writes `approved-tes-numbered-list.xlsx/.pdf`: every student in the system A to Z, numbered, plus 5 rows not in the system. Registrar list now lists every student in the system except one demo student (works after a clean start too) |
| Start clean in all roles | New `php artisan demo:clean` (type CLEAN ALL): removes all applications, documents and files, upload histories (approved lists, Registrar lists, external grantee lists), grantees, payroll, batches, notices, recommendations, messages, change requests, e-mail log, activity log. Keeps all accounts, programs, requirements, settings, announcements. Refused when a real student is in the database; demo computer only |

Tests: +1 (`StaffAlertsAndStudentResetTest`). Backend total **124 passed**. Browser check here: 8 files uploaded with one press; review window opened and closed on Auto-Review with no page errors. Numbered list processed: 31 matched (Binag approved), 5 unmatched.

## Part 6 (round 27) — Recommendations, drag and drop, Registrar list, payroll signatories, messages

No new migration. Nothing committed or pushed.

| # | Issue | Cause found | Change made |
|---|---|---|---|
| 1 | AI Recommendations showed only Binag | `demo:clean` emptied the recommendations. The page re-scores everyone only when the table is EMPTY; Binag's profile/upload re-scored her alone, so the table was no longer empty and the others were never scored again. It always used the real database (demo students are database records too) | The page now re-scores everyone when no full run was recorded, a program changed after it, or an active student has no score. `demo:clean` re-scores everyone at the end. Tested: 1 student -> 31 students |
| 2 | Drag and drop | Not available | New `components/FileDrop.jsx`: drop files on the student's document rows, "Upload all documents", My Documents, Approved Lists, Enrollment, External Grantees. Choose File still works; a dropped file goes through the same checks |
| 3 | Registrar list "only Binag" out of 55 | Reading was correct (55 rows saved). Step 2 checks only approved applicants waiting for verification (after a clean start: only Binag), and the saved rows were never shown | Before saving: all rows read are shown. After saving: new "2. What is in the list" shows every row with what it means (waiting / grantee / already verified / in the system, no grant / name differs / not in the system). New `GET /staff/enrollment-lists/{id}/rows`; earlier lists have "View rows" |
| 4 | Enrollment workflow | Already: nothing is enrolled until staff press Record | Kept, and made visible: 1 upload + preview, 2 what is in the list (read-only), 3 Verify All Enrollments, then Record |
| 5 | Payroll form signatories | Layout already followed the OAS form, but signatory NAMES defaulted to empty and printed as blank lines; capitals turned "MSc/PhD" into "MSC/PHD" | Defaults = names and positions from the OAS form (editable in Settings); degree printed as written. PDF, Word and preview |
| 6 | Two message sections | The staff bell listed open messages twice ("What to do next" + "Student messages"); the Messages page listed oldest first | One list in the bell + link "All student messages"; Messages page newest first, list on the left and the open conversation on the right; the bell opens that conversation |

Tests: `Round27FixesTest` (4 new). Backend total **128 passed**. Browser check here: Registrar list dropped -> 55 rows previewed and listed (30 in the system, 25 not); message list newest first; bell has one list; a file dropped on a document row was uploaded.

## Part 7 — Cancel buttons and Delete file

No new migration. Nothing committed or pushed.

| Where | Button | What it does |
|---|---|---|
| Student application / My Documents | **Cancel upload** (row and top) | Stops the file being sent; with "Upload all chosen files" the rest is not sent. The file stays chosen. The page reloads to show the real state (a file that had fully arrived may have been kept) |
| Student application / My Documents | **Remove chosen file** | Un-chooses a file that was not uploaded |
| Student application / My Documents | **Delete file** (asks first) | New `DELETE /student/documents/{id}`: only the student's own file, only in My Documents or a draft / needs-action application; files of a submitted application are kept (422). Stored file removed when nothing else uses it; logged |
| "Upload all documents" box | **Cancel upload** | Stops the bulk upload; files not sent stay "Ready" |
| Student and staff document rows | **Cancel check**, **Cancel AI checks** | New `POST /documents/{id}/cancel-check`: the document goes back to its earlier status at once; a check waiting for the AI worker is skipped; a result arriving later is thrown away. "AI check all" stops after the current file |
| Approved Lists | **Cancel reading**, **Remove chosen file** | Stops waiting for the file to be read; nothing is saved |
| Enrollment | **Cancel reading** | Same |
| External Grantees | **Cancel** | Stops waiting; if the server had already finished, the list waits in Upload history (Discard it there) |

Limit (honest): cancelling only stops what the server has not finished. Without the AI worker window, an AI check runs inside the web request (php artisan serve does one request at a time), so Cancel frees the page but the check may still finish and save; the page then says "had already finished". With the AI worker running (start-csu.ps1 starts it), Cancel stops it properly.

Tests: `CancelAndDeleteTest` (5 new). Backend total **133 passed**. Browser check here (AI worker + slow stand-in AI): remove chosen file, cancel upload, cancel AI check (no result saved 10 s later), delete file, cancel reading a list.

## Part 8 (round 28) — UI/UX refresh (design system)

No new package, no migration. Nothing committed or pushed. The reference screenshot did not arrive, so the written requirements were followed.

**Brand check:** CSU colours are green and gold (university infobox); the shades already in the app come from the CSU logo, so the existing green #004d26 and gold #d4af37 were kept, not invented. Logo files unchanged.

| Area | Change |
|---|---|
| Design system | New `frontend/src/styles/theme.css`, loaded after `index.css` in `main.jsx`: one set of tokens (neutrals, green/gold, radius, soft shadows, motion) and the final look of shared parts, so every page follows it without rewriting page rules |
| Navigation | Line icons for every menu item (new `components/Icon.jsx`, no icon package), green-tint active item with a gold marker, quieter section headings; ☰ becomes × when the phone menu is open; sidebar slides with an eased transition |
| Buttons | Same height/radius everywhere; hover lift + colour, pressed state, focus ring, clear disabled state; `aria-busy` shows a spinner (used on Upload all, Submit, Read file, Process list, Save list); calmer Approve/Reject/Cancel colours |
| Cards, tables, badges | Rounded cards with soft shadow; table heads on a light band, row hover, softer separators; status badges as pills with a dot |
| Forms and uploads | 42 px inputs, green focus ring, red ring for invalid input, required-field asterisk, styled "Choose File" button, modern drag-and-drop area |
| Dashboards | Number cards with icons (gold tile when a number needs action, only real numbers); shortcut tiles use line icons instead of emoji; student dashboard shows the status numbers before "Scholarships that fit you" |
| Motion | Modal backdrop fade + window pop, toasts slide in and out with an icon, dropdowns (bell, profile, export) drop in, carousel slides eased, details open with a fade; all off with "reduce motion" |
| Loading | Pages show a shimmering outline of the page instead of a lone spinner |
| Responsive | Fixed Reports spilling 450 px sideways on phones (long dropdown option); grid/flex children can shrink; bigger tap targets on phones |
| Speed (found while testing) | Student dashboard asked the AI service for "field fit" on every visit; `php artisan serve` answers one request at a time, so every other request waited (8 s with a slow AI). Now asked at most every 10 minutes per student (uploads and profile changes still refresh it) — `StudentController::matches`, `EligibilityEngine::recalculate(..., $withFit)` |

Checked here: production build OK; 22 pages (student, staff, admin) at 1366 px and 390 px: no page errors, no sideways scrolling; bell, toast, confirm window, phone menu, loading state looked at. Backend **133 passed**. `npm run lint` could not run here (no npm in this sandbox): run it on your computer.

## Part 9 — Lint fixes and a self-restarting AI worker

| Problem | Fix |
|---|---|
| `Icon.jsx` exported a function (fast-refresh rule) | `iconFor` moved to `lib/icons.js` |
| `aiChecks.js`: `poll` / `start` used before declared (they call themselves later) | They call themselves through refs set in an effect |
| `GeneralPayrollPage.jsx`: `signName` unused | Real miss: the on-screen payroll preview still printed "MSC/PHD" (a non-breaking space stopped the earlier change). Now uses `signName` like the PDF and Word file |
| `MessagesPage.jsx`: setState inside an effect | `?open=` is read once when the page opens (the layout re-opens the page when the address changes) |
| `MyDocumentsPage.jsx`: ref read during render | "Cancel upload" now shows from state |
| AI worker window closed by itself with no message | `start-csu.ps1` runs the worker in a loop: it shows the exit code and why (0 = stop signal or lost MySQL connection, 12 = memory, other = error) and starts again after 5 s; `--memory=512 -v` |

Checked here: build OK; bell -> conversation, ?open=, My Documents Cancel upload, AI check start -> worker -> saved (validated). `npm run lint` not available here: run it on your computer.

## Part 10 (round 29) — More applicants to process, small display fixes

No new migration, no new package. Nothing committed or pushed.

| Change | Files |
|---|---|
| New `php artisan demo:applicants [count] [--program=TES]` (default 12): demo students (fictional, `is_demo`) get a SUBMITTED application with every required document, so staff process 10-15 applications next to Gleslimie. Readable sample PDFs; every 4th has a wrong document, every 5th a surname typo, every 7th a blank scan, for the AI to flag. Documents start "not AI-checked". Real students are never used; demo computer only. Undo: `demo:clean` or `demo:reset-student <ID>` | `backend/app/Console/Commands/DemoApplicants.php`, `backend/tests/Feature/DemoApplicantsTest.php`, `backend/tests/Feature/StaffAlertsAndStudentResetTest.php` |
| Student IDs no longer break onto two lines (Auto-Review, Enrollment preview and rows) | `AutoReviewPage.jsx`, `EnrollmentPage.jsx` |
| Bell: "Open review" button readable on new (gold) items; Auto-Review "Why" column keeps a readable width | `frontend/src/styles/theme.css` |

Tests: backend **135 passed** here (GitHub main 9fe69dc: 133 passed). The AI result for the planted problems was not checked here with the real AI service (NOT VERIFIED): run AI check all on your computer.
