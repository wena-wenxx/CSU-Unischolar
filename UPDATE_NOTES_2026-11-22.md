# QUICK START (one go)

1. Start **MySQL** in XAMPP.
2. Extract this zip **into** `C:\Projects\CSU-Unischolar`. The folders `backend`, `frontend` and `ai-service` merge with yours; choose **Replace the files**.
3. Double-click **`APPLY_UPDATE.bat`** in the project folder. It:
   - backs up your database to `backups\`;
   - runs the new migrations;
   - clears the Laravel cache;
   - runs the backend tests, the AI tests and a frontend build;
   - shows a summary at the end.
4. Start the system as usual and open **Processing Batches** in the staff sidebar.

If anything fails, the script stops and says why. Your data is in `backups\`; to restore it, use phpMyAdmin → Import.

---

# CSU UniScholar – Batches, Data Bank and Workflow Update (Nov 22, 2026)

These files are based on your commit `a35a4f1` (Nov 21). Nothing was committed or pushed to GitHub.

## What was checked

- **Backend tests:** 89 passed, 627 assertions. This includes 12 new tests in `tests/Feature/BatchAndDataBankTest.php`.
  - They ran on SQLite.
  - They also ran on MariaDB 10.11, which is close to XAMPP's MariaDB 10.4.
- **AI-service tests:** 94 passed, including 1 new test, `tests/test_page_methods.py`.
- **Migrations on a copy of your Nov 20 database dump (MariaDB):**
  - All 3 migrations ran.
  - All 269 applications were linked to a batch.
  - All 80 scholar records got their school year.
  - All 110 payroll entries were linked to a batch.
  - 130 students were marked as demo. The real test student was not marked.
  - Rolling back and migrating again also worked.
- **Demo reset:**
  - `demo:reset --full` gave back exactly the same counts (131 students, 262 applications, no duplicates).
  - The plain `demo:reset` left the 10 named demo accounts and 0 applications.
- **Browser test** of the new and changed pages: no script errors.

## How to apply (on your laptop)

1. **Back up the database first.** Use phpMyAdmin → Export, or run:

   ```
   C:\xampp\mysql\bin\mysqldump -u root csu_unischolar > backup-before-batches.sql
   ```

2. **Copy each file into the same path** under `C:\Projects\CSU-Unischolar`. These are complete replacement files; new files are marked NEW.

3. **Run the migrations.** They only add columns and tables; existing data is kept.

   ```
   cd backend
   php artisan migrate
   ```

   Do **not** use `migrate:fresh` on your real database.

4. **Run the tests:**

   ```
   php artisan test
   cd ..\ai-service
   python -m pytest
   ```

5. **Restart the frontend** (`npm run dev`). The new page is **Processing Batches** in the staff sidebar.

6. **Optional .env settings:**
   - `MAIL_MAILER=log` keeps e-mail off. This is the recommended setting until the university authorizes sending to carsu.edu.ph accounts.
   - `DEMO_RESET_ALLOWED=true` lets you run `php artisan demo:reset`. Only set it on the demo laptop.

## Files

### Backend: new files

- `database/migrations/2026_11_22_000001_create_processing_batches_table.php`
- `database/migrations/2026_11_22_000002_link_records_to_processing_batches.php`
- `database/migrations/2026_11_22_000003_scholar_records_per_school_year.php`
- `app/Models/ProcessingBatch.php`
- `app/Http/Controllers/ProcessingBatchController.php`
- `app/Support/MailMode.php`
- `app/Support/DemoData.php`
- `app/Console/Commands/DemoReset.php`
- `tests/Feature/BatchAndDataBankTest.php`

### Backend: changed files

| File | Change |
|---|---|
| `routes/api.php` | Batch routes; link / set-aside routes for list rows; `GET /staff/mail-mode` |
| `app/Models/Application.php` | Batch, semester and enrollment method. A new application goes into the open batch of its program and period. |
| `app/Models/ScholarRecord.php` | School year, semester and batch. A new record defaults to the current school year. |
| `app/Models/AgencyListUpload.php` | Batch, file fingerprint, open rows |
| `app/Models/PayrollRecord.php` | Batch |
| `app/Models/Student.php` | `is_demo` |
| `app/Models/ApplicationStatusLog.php` | `changer()` (who made each step) |
| `app/Http/Controllers/AgencyListController.php` | Batch, same-file check, link / set aside unmatched rows, honest e-mail wording |
| `app/Http/Controllers/ScholarRecordController.php` | One record per program per school year; the older year is marked completed |
| `app/Http/Controllers/EnrollmentController.php` | Records the method: manual |
| `app/Http/Controllers/EnrollmentListController.php` | Records the method: registrar_list |
| `app/Http/Controllers/PayrollController.php` | Payroll entries carry the batch; batch filter |
| `app/Http/Controllers/DataBankController.php` | New filters (academic year, semester, batch, application status, grantee status); processing history |
| `app/Http/Controllers/ApplicationController.php` | Batch filter; e-mail mode |
| `app/Http/Controllers/RecommendationController.php` | Honest e-mail wording |
| `database/seeders/DatabaseSeeder.php` | Fills batches and school years; marks demo students |
| `config/demo.php`, `.env.example` | `DEMO_RESET_ALLOWED` |
| `tests/Feature/TesBillingListTest.php` | Test fix: it used a status (`forwarded`) that MySQL refuses; the real status is `complete`. |

### AI service

- `list_reader.py`: reports how each PDF page was read (`page_methods`, `text_pages`, `ocr_pages`).
- `tests/test_page_methods.py` (NEW).

### Frontend

- `src/pages/staff/ProcessingBatchesPage.jsx` (NEW)
- `src/App.jsx`, `src/components/Layout.jsx`: route and sidebar link
- `src/pages/staff/AgencyListsPage.jsx`: batch choice, same-file check, how pages were read, link or set aside unmatched rows
- `src/pages/staff/DataBankPage.jsx`: new filters, school year and batch columns, processing history
- `src/pages/staff/StaffApplicationsPage.jsx`: batch filter (also `?batch=<id>`); honest e-mail message
- `src/lib/format.js`: enrollment shows how it was checked

## Demo reset

```
php artisan demo:reset          # demo students again, empty queue (for a clean demo cycle)
php artisan demo:reset --full   # demo students again, with the full demo history
```

The reset:

- works only with `APP_ENV=local` and `DEMO_RESET_ALLOWED=true`;
- asks you to type `RESET DEMO`;
- removes only students marked `is_demo` (`student1..10@carsu.edu.ph` and `...@demo.carsu.edu.ph`);
- never removes staff, admin, programs, settings, the activity log, the real test student or any other student.
