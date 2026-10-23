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
3. Press **Open form to print / save as PDF** or **Download Word (.docx)**.

The form is for one program and one period because each OAS sheet is for one batch and one release.

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

## Printing

In the print window choose: paper **Legal / Long (8.5 × 13 in)**, **Landscape**, **Headers and footers off**, and
**Background graphics on** (for the yellow boxes). Choose **Save as PDF** to keep a PDF. One sheet = one page.

The Word file uses the same page size and can be edited before printing (for example to add deductions).

## Where it is in the code

- `backend/app/Support/GeneralPayrollForm.php` — builds the sheets (`build`) and the Word file (`docx`, written with
  PHP's ZipArchive; needs `extension=zip` in `php.ini`).
- `backend/app/Http/Controllers/PayrollController.php` — `POST /api/payroll/form` (JSON) and
  `POST /api/payroll/form/docx` (Word). Staff only. Each Word download is written to the Activity Log.
- `frontend/src/pages/staff/GeneralPayrollPage.jsx` — the printable page (`/staff/payroll/form`).
- Tests: `backend/tests/Feature/GeneralPayrollFormTest.php`.

## Limits

- Tested by rendering the Word file in LibreOffice and the print page in Chromium. **Check it once in Microsoft
  Word and on the OAS printer** before using it for a real release.
- The old CSV of the payroll list was removed from the Payroll page. Reports still export CSV files.
