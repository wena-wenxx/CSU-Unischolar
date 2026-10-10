# Demo Files for Testing the AI Document Check

All demo files are **fictional**. Every person's middle name is "Demo", every file carries a
**"SAMPLE · NOT AN OFFICIAL DOCUMENT"** watermark, and none uses a real seal, logo, agency name or form number.
**Never put real CSU student data or real IDs into the system.**

## Where the files are

| Location | What it is |
|---|---|
| `backend/database/seeders/demo-files/` | PDFs that `php artisan migrate:fresh --seed` attaches to the demo applications automatically. |
| `csu-unischolar-demo-files.zip` (sent separately) | The full kit for uploading by hand: 10 students × 9 documents (PDF), PNG "scans" for Juan, problem files, and editable templates. |

Kit layout:

```
csu-unischolar-demo-files/
├── students/2026-00001-juan-student/ … 2026-00010-grace-lim/
│     certificate-of-registration.pdf   certificate-of-grades.pdf
│     certificate-of-indigency.pdf      birth-certificate.pdf
│     valid-id.pdf                      barangay-clearance.pdf
│     certificate-of-good-moral-character.pdf
│     parents-income-tax-return.pdf     recommendation-letter.pdf
│     (Juan's folder also has a .png of each, to test OCR on "scanned" images)
├── problem-files/
│     cor-of-a-different-person.pdf                     → should be FLAGGED (name)
│     maria-barangay-clearance-uploaded-as-indigency.pdf → should be FLAGGED (wrong document)
│     blurry-unreadable-scan.png                         → should be FLAGGED / Needs Review
└── editable-templates/   (one .html per document type — open in Chrome, edit, Print → Save as PDF)
```

## Download or create?

| Document | Download a real one? | What to do |
|---|---|---|
| Student ID (CSU-style or DepEd-style) | **No** | Real templates carry official logos and may show real people. Use `valid-id.pdf` from the kit, or make your own with the layout below. |
| Certificate of Registration (COR) | **No** | Real CORs come only from the Registrar and contain real data. Use the kit or the layout below. |
| Certificate of Grades | **No** | Same as COR. |
| Certificate of Indigency | **No** | Barangay templates online often include real seals and officials' names. Use the kit. |
| Birth Certificate | **No** | PSA forms are official security documents. Use the kit's *sample* "Certificate of Live Birth". |
| Barangay Clearance | **No** | Same as Indigency. |
| Certificate of Good Moral Character | **No** | Real ones carry a school seal and a real Guidance Counselor's name. Use the kit. |
| Parents' Income Tax Return | **No** | BIR forms contain real tax numbers. The kit has a plain *sample* summary with no agency name or form number. |
| Recommendation Letter | **No** | Use the kit's letter from a fictional adviser. |

Everything you need is in the kit. If you want more files (for example a new demo student), use the 5-minute
layouts below in Word or Google Docs, then **File → Download / Save as → PDF**.

## 5-minute layouts and what the AI checks

The AI compares each file with **the applicant's first + last name**, the **student ID** (school documents only)
and a few **keywords** for that kind of document. A file is flagged as the *wrong document* only when **none** of
its keywords appear. Always add a line `SAMPLE — NOT AN OFFICIAL DOCUMENT` at the top.

### 1. Certificate of Registration (COR)
```
SAMPLE — NOT AN OFFICIAL DOCUMENT
CERTIFICATE OF REGISTRATION
1st Semester, Academic Year 2026–2027
Name: STUDENT, JUAN DEMO            Student ID No.: 2026-00001
Course: BS Information Technology   Year Level: 4th Year
[table] Code | Subject | Units | Schedule   (5–6 rows)
Total units: 17
```
AI checks: name, **student ID**, keywords *REGISTRATION, ENROLL, UNITS, SUBJECT, SEMESTER*.

### 2. Certificate of Grades
```
SAMPLE — NOT AN OFFICIAL DOCUMENT
CERTIFICATE OF GRADES
2nd Semester, Academic Year 2025–2026
Name: STUDENT, JUAN DEMO            Student ID No.: 2026-00001
[table] Code | Subject | Units | Final Grade | Remarks
General Weighted Average (GWA): 1.47
```
AI checks: name, **student ID**, keywords *GRADE(S), GWA, AVERAGE, SEMESTER, SUBJECT, UNITS, REPORT*.

### 3. Valid ID (student ID card)
A landscape box (Insert → Table, 1 cell) with:
```
STUDENT IDENTIFICATION CARD     (sample only)
[grey box: PHOTO]   Name: JUAN DEMO STUDENT
                    ID No.: 2026-00001
                    Course: BS Information Technology
_____________ Student signature          SAMPLE — NOT A REAL ID
```
AI checks: name, **student ID**, keywords *IDENTIFICATION, VALID, ID NO, ID NUMBER, SIGNATURE, STUDENT*.
Do not use a real photo; leave the grey box.

### 4. Birth Certificate (sample)
```
SAMPLE — NOT AN OFFICIAL DOCUMENT
Sample Civil Registry — for testing only
CERTIFICATE OF LIVE BIRTH
Name of child: JUAN DEMO STUDENT     Date of birth: January 15, 2004
Mother: ELENA DEMO STUDENT           Father: RAMON DEMO STUDENT
```
AI checks: name, keywords *BIRTH, LIVE BIRTH, PSA, REGISTRY, CIVIL REGISTRAR*. No student ID needed.
Do **not** copy the real PSA form, logo or security patterns.

### 5. Certificate of Indigency
```
SAMPLE — NOT AN OFFICIAL DOCUMENT
Barangay Sample, Butuan City (fictional)
CERTIFICATE OF INDIGENCY
This is to certify that JUAN DEMO STUDENT ... belongs to an indigent family with a low income.
```
AI checks: name, keywords *INDIGENCY, INDIGENT, LOW INCOME*. No student ID needed.

### 6. Barangay Clearance
```
SAMPLE — NOT AN OFFICIAL DOCUMENT
Barangay Sample, Butuan City (fictional)
BARANGAY CLEARANCE
This is to certify that JUAN DEMO STUDENT is a resident ... with no derogatory record.
```
AI checks: name, keyword *CLEARANCE*. No student ID needed.
Don't write "indigent" on a clearance (or "clearance" on an indigency certificate), or the two can't be told apart.

### 7. Certificate of Good Moral Character
```
SAMPLE — NOT AN OFFICIAL DOCUMENT
Office of Student Affairs (sample)
CERTIFICATE OF GOOD MORAL CHARACTER
This is to certify that JUAN DEMO STUDENT, Student ID No. 2026-00001, has shown good moral character ...
```
AI checks: name, keywords *GOOD MORAL, MORAL CHARACTER*. No student ID needed.

### 8. Parents' Income Tax Return (sample summary)
```
SAMPLE — NOT AN OFFICIAL DOCUMENT
ANNUAL INCOME TAX RETURN — SUMMARY (sample, taxable year 2025)
Taxpayer: RAMON DEMO STUDENT (father of the applicant)
Gross compensation income: PHP 96,000.00     Taxable income: PHP 0.00     Tax due: PHP 0.00
```
AI checks: only the **family surname** (the names on it are the parents', not the student's), keywords
*INCOME TAX, TAX RETURN, TAXABLE INCOME, INTERNAL REVENUE, TAX DUE*. No student ID needed.

### 9. Recommendation Letter
```
SAMPLE — NOT AN OFFICIAL DOCUMENT
October 1, 2026
To the Scholarship Committee:
I am pleased to recommend JUAN DEMO STUDENT for the scholarship ...
(signed) Prof. Sample Adviser, Department of Sample Studies
```
AI checks: name, keyword *RECOMMEND*. No student ID needed.

## Expected results (checked on 6 Oct 2026)

These results come from running the real `validation.py` rules on the text of the kit's PDFs.

| Test | Expected result |
|---|---|
| Any student's 9 PDFs, each uploaded in the matching slot (90 files) | **Validated**, no flags (90 of 90) |
| `cor-of-a-different-person.pdf` uploaded as Rosa's COR | **Flagged**: name not found (match about 47%) + student ID not found |
| Maria's Barangay Clearance uploaded as her Certificate of Indigency | **Flagged**: "does not look like the requested document" |
| Juan's COR uploaded as a Birth Certificate | **Flagged**: wrong document |
| Pedro's Birth Certificate uploaded as a Valid ID | **Flagged**: wrong document |
| Rosa's Parents' Income Tax Return (surname *Villanueva* on it) | **Validated** — only the surname is checked |
| Juan's Income Tax Return uploaded in Rosa's ITR slot | **Flagged**: "Applicant's surname (Villanueva) was not found on this parent's document" |
| A Recommendation Letter uploaded as Good Moral | **Flagged**: wrong document |
| AI service stopped | Document goes to **Needs Review** |

**Not yet checked:** the PNG images and `blurry-unreadable-scan.png`, which need PaddleOCR. Run these on your
computer with the real AI service and write down what you see; don't assume the result.

## Bulk demo documents

The 120 bulk students' documents (about 4,200 PDFs) are generated by the seeder into
`storage/app/public/documents/demo/bulk/`. Each is a one-page PDF with a diagonal "SAMPLE - NOT OFFICIAL" watermark
and a red banner "SAMPLE FOR CSU UNISCHOLAR TESTING ONLY - NOT AN OFFICIAL DOCUMENT - FICTIONAL PERSON".
Their AI results were computed by the seeder with a PHP copy of the same `validation.py` rules (checked to give
identical results on every seeded file). A few are deliberately wrong (another person's file, the wrong document,
a missing ID, an unreadable blank page) so the Needs Action, Flagged and Needs Review lists are not empty.

## Known limits (say these honestly to the panel)

- **Grades uploaded as a COR is not caught.** Both documents genuinely contain *SUBJECT, UNITS, SEMESTER*,
  so a keyword check cannot tell them apart. Staff still review every document.
- **Demo surname "Student".** Juan, Maria and Ana's surname is literally "Student", which is also a Valid-ID
  keyword, so *their* wrong uploads into the Valid ID slot may not be flagged. Use **Pedro, Rosa or another student**
  when demonstrating wrong-document detection.
- The AI only **flags**; it never approves or rejects. No accuracy figures have been measured, so do not quote any.

## Suggested panel demo order (about 10 minutes)

1. `php artisan migrate:fresh --seed` before the panel arrives.
2. **Student (Liza, student5):** apply for **Culture and Arts** → upload her 6 PDFs → Submit.
3. **Staff:** Applications → search `2026-00002` → Review **Maria** → *Run AI check* on Certificate of Indigency → show the flag.
4. Review **Rosa** → *Run AI check* on COR → show the name-mismatch flag.
5. Review **Mark** → *Verify enrollment* → Scholar Records → *Tag as Grantee*.
6. **Payroll** → *Prepare payroll for all enrolled scholars* → show "Payroll prepared for X scholars … skipped".
7. **Data Bank** → search `2026-00003` → show Ana's full history.
8. **Reports** → export the Payroll CSV and open it in Excel.
