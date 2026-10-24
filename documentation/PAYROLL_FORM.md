# General Payroll — General Form No. 6-A (round 12)

ScholarGuide fills in the OAS **General Payroll** form (General Form No. 6-A, Revised July 1, 1995) from the
payroll list. It replaces the old CSV export of the payroll list. The layout follows the sample sheets the OAS gave
us (photos of a filled-in form).

## How to make one

1. **Payroll → 2. Payroll list.** Click **one program tab** and choose **one period** in the Period filter.
   (Tick entries first if only some of them should be on the form.)
2. Press **General Payroll (Form 6-A)**. Check the boxes:
   - **Batch** — e.g. `TES GRANTEES BATCH 1` (printed in "Release of … balance for the sum herein specified").
   - **Journal Voucher** — e.g. `OR#0146829` (optional).
   - **Purpose** — e.g. `1st Semester AY 2026-2027 Stipend`.
   - **Prepared by** name and position (your own name is filled in).
   - **First sheet no.** (to continue numbering from an earlier form) and **Rows per sheet** (default 15).
   - **Separate WITH ATM / WITHOUT ATM sheets** (on by default, like the OAS sample).
3. Press **Download PDF** (a ready long bond, landscape PDF file, no print window), **Download Word (.docx)**
   (to edit), or **Preview and print** (the form on screen, with the same two download buttons and a Print button).

The form is for one program and one period because each OAS sheet is for one batch and one release.

## Layout (checked against the OAS photos, round 12)

- Header in two rows: **Less: Miscellaneous / CHED Grants** is one heading over two empty sub-columns; every
  other heading fills both rows.
- Only the **Journal Voucher** box and the **Release of … balance** band are yellow; the purpose line is underlined;
  **WITH ATM / WITHOUT ATM** in red on the right.
- Signatures in two rows, like the paper form: (1) *Prepared By* OAS staff · *Noted by* Scholarship Coordinator ·
  OAS Director · *As to correctness of enrollment data* University Registrar · VP for Student Affairs, then a line
  across; (2) *Funds Available* Chief Accountant · University President · University Cashier · two witness lines.
  Names are printed in bold capitals with the position below (no signature line: they sign over the name).
- The spelling mistakes on the paper copy ("recieve", "compenc\sation", "Miscelaneous") are not copied.

## What is filled in

| Column | Filled with |
|---|---|
| No., ID NO., NAME | number, Student ID, `LAST, FIRST M.` — sorted A to Z |
| 1ST SEM. / 2ND SEM. | the amount, in the column of the payroll period (the rate is printed under the heading) |
| TOTAL, Net Amount Received | the amount |
| Position, Less: Miscellaneous, CHED Grants, TOTAL DEDUCTIONS | left empty (written by hand if needed) |
| Initial of Witness to Payment | left empty, with the row number, for the grantee's signature |
| Totals | sheet total in red; ***NOTHING FOLLOWS*** after the last name; GRAND TOTAL on the last sheet when a group has more than one sheet |
| Certifications (1)–(4) | the names and positions from **System Settings → Payroll form signatories** |

ScholarGuide does **not** compute deductions: no deduction rules were given to us, so none are invented.

## Signatory names

The system admin types them in **System Settings → Payroll form signatories** (Scholarship Coordinator, OAS
Director, University Registrar, VP for Student Affairs and Auxiliary Services, Chief Accountant, University
President, University Cashier). The positions are pre-filled; the names start **empty** and print as a blank line
until they are typed. Real officials' names are not written in the code.

## PDF and printing

**Download PDF** makes the PDF on the server with **dompdf** (free, LGPL licence), so no print window is needed.
The page is already long bond, landscape. To print it: open the PDF, choose the long (8.5 × 13 in) paper and
**Actual size** (not "Fit"). One sheet = one page.

Install dompdf once (needs internet), in the `backend` folder:

```
composer require dompdf/dompdf
```

This adds it to `composer.json` and `composer.lock` (commit both). If it is not installed, the app says so instead of
making a broken file.

**Print** on the Preview page uses the browser instead: paper **Legal / Long**, **Landscape**, **Headers and footers
off**, **Background graphics on**.

The Word file uses the same page size and can be edited before printing (for example to add deductions).

## Where it is in the code

- `backend/app/Support/GeneralPayrollForm.php` — builds the sheets (`build`) and the Word file (`docx`, written with
  PHP's ZipArchive; needs `extension=zip` in `php.ini`).
- `backend/resources/views/pdf/general-payroll.blade.php` — the PDF layout (tables only; dompdf does not support
  flex or grid). `GeneralPayrollForm::pdf()` turns it into a PDF.
- `backend/app/Http/Controllers/PayrollController.php` — `POST /api/payroll/form` (JSON),
  `POST /api/payroll/form/pdf` (PDF) and `POST /api/payroll/form/docx` (Word). Staff only. Each download is written
  to the Activity Log. If a file cannot be made, the real reason is shown to staff (for example "turn on
  extension=zip" or "composer require dompdf/dompdf").
- `backend/config/cors.php` — `exposed_headers: Content-Disposition`, so the downloaded file keeps its name.
- `frontend/src/pages/staff/GeneralPayrollPage.jsx` — the printable page (`/staff/payroll/form`).
- Tests: `backend/tests/Feature/GeneralPayrollFormTest.php`.

## Limits

- Tested by rendering the Word file in LibreOffice, opening the dompdf PDF, and printing the page in Chromium. **Check it once in Microsoft
  Word and on the OAS printer** before using it for a real release.
- The old CSV of the payroll list was removed from the Payroll page. Reports still export CSV files.
