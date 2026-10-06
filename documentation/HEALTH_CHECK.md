# Health check — 6 October 2026

Checked: `main` at commit `98dcc36`. The repository has only two branches, `main` and
`feature/gleslimie-frontend`, and both point to the same commit, so this report covers every branch.
(`wena/backend-workflow` was deleted after merging.)

Legend: **Broken** = does not work · **Partial** = works but has a gap · **Complete** = works as intended.
Every Broken/Partial item below lists the file, the problem and the fix that was applied in this update.

## Broken (all fixed in this update)

| # | File | Problem | Fix |
|---|---|---|---|
| 1 | `backend/routes/api.php` | `GET /api/user` was a closure; `AuthController@me` existed but was never used. | Route now calls `AuthController@me`; unused `Request` import removed. |
| 2 | `backend/app/Http/Controllers/PayrollController.php` | `ready()` had no route (dead code; "Payroll Ready" is computed by the dashboard). | Removed. |
| 3 | `backend/app/Http/Controllers/ScholarRecordController.php` | `myRecords()` had no route, plus an unused `use App\Models\Student;`. | Removed both. |
| 4 | `backend/app/Http/Controllers/ScholarshipController.php` | `listRequirements()` and `updateRequirement()` had no routes (requirements come with `GET /scholarships`). | Removed. |
| 5 | `backend/config/cors.php` | Missing. A frontend on another domain (Vercel) would be blocked by the browser. | Added; allowed origins come from `FRONTEND_URL` (comma-separated). |
| 6 | `backend/.env.example` | Used SQLite, but the project's database is MySQL 8. | Now `DB_CONNECTION=mysql`, database `csu_unischolar`, with a `CREATE DATABASE` note. |
| 7 | `backend/app/Http/Controllers/ApplicationController.php` (`index`) | The staff list loaded every document and AI result for every application: **7.9 MB / 2.4 s** with the volume data. | Loads only the columns the table, filters and CSV reports use: **1.2 MB / 0.37 s**. The review window still loads the full application through `show()`. |
| 8 | `ai-service/validation.py` | Only 6 of the 9 document types had keywords; Good Moral, Income Tax Return and Recommendation Letter were never checked for "wrong document". | Keywords added for all 9; the ITR checks only the family surname (the names on it are the parents'). 6 new tests. |
| 9 | `frontend/src/components/Layout.jsx` | Closed the phone menu with `setState` inside `useEffect` — flagged by `eslint-plugin-react-hooks` v7. | Menu remembers the page it was opened on; it closes itself when the page changes, no effect needed. |
| 10 | `frontend/src/pages/staff/DataBankPage.jsx` | Same lint pattern (async state update started from an effect). | Fetch uses `.then/.catch`; same behaviour. |
| 11 | `ai-service/make_sample_docs.py` | Out of date (6 document types, old names); replaced by the seeded demo files and the demo kit. | Deleted. |

## Partial

| # | Where | Gap | Recommendation |
|---|---|---|---|
| 1 | `backend/routes/api.php` `POST /register` | Route exists, but there is no sign-up page (accounts are seeded). | Keep for the defense — CSU accounts would come from the Registrar. Say so if asked. |
| 2 | `DELETE /api/scholarships/{id}`, `GET /api/scholar-records/{id}` | Working routes that the UI never calls. | Leave them; they are useful from Postman. |
| 3 | `frontend/public/csu-letterhead.jpg` | Not referenced by any page. | Harmless; delete it if you want a smaller build. |
| 4 | AI: Certificate of Grades uploaded as a COR | Both contain *SUBJECT, UNITS, SEMESTER*, so the keyword rule can't tell them apart. | Known limit; staff review every document. |
| 5 | Lint / build | `npm run lint` and `npm run build` could not run here (npm downloads blocked). | Run both on your computer before the defense. |

## Complete

- **Backend:** boots; all **32 routes** point to methods that exist; migrations run clean; models match their tables;
  no duplicate classes; `php artisan test` → 2 passed. Rules enforced on the server: one active scholarship per student,
  no duplicate application, only active programs accept applications, students can open only their own applications,
  enrollment must be verified before tagging, payroll skips anyone already paid for
  that period.
- **Frontend:** all **40 API calls** match a backend route; no broken imports; React Router with protected student/staff
  areas, Back/Refresh work; toasts and confirm windows instead of `alert/confirm/prompt`; batch payroll; phone menu;
  remarks required before Needs Action; print styles; CSU colours, fonts, logo and login page.
- **AI service:** all files compile; **25 rule tests pass**; text PDFs are read without OCR; images use PaddleOCR when
  installed; if the service is down, documents go to **Needs Review** instead of failing.
- **Data:** `migrate:fresh --seed` creates 130 students, 20 programs, 560 applications in every status, 76 scholar
  records, 109 payroll entries, 4,287 documents and 3,641 AI results in about 10 seconds; every dashboard number is
  above zero; running the seeders again adds nothing twice.

## Recommendations (not built — out of scope or optional)

1. Add pagination to the staff Applications table if the real office will have thousands of applications.
2. Move AI checks to a Laravel queue if many documents must be checked at once (today staff check one at a time).
3. Keep the AI as a *flagging* tool only. Do not quote accuracy figures until you have measured them on a labelled
   test set.

## How this was checked

On a Linux test machine with PHP 8.3 and **SQLite** (MySQL was not available there), headless Chromium for the
browser walkthrough, and a stand-in AI server that runs the real `validation.py` rules on text extracted from the demo
PDFs. Not checked: MySQL, the real FastAPI/PaddleOCR service, `npm run build/lint`, and the Docker deployment files.
