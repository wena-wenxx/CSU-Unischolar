# Is a document genuine? (round 12)

## The honest answer

ScholarGuide **cannot prove** that a document is genuine, and no claim is made that it can.

- The **AI check** (PaddleOCR / PyMuPDF + `validation.py`) reads the text and checks that it is the right kind of
  document, that the name matches the student, and that nothing required is missing. A well-made fake with the
  right text passes this check.
- **Uploading "credible samples" would not fix this.** Comparing to a few samples only checks that a file *looks
  like* the samples. Training a model to detect fakes needs many real and fake documents, which we do not have
  (and should not collect). So we do not do it.

What we added instead: a **person** checks the document using the issuing office's own verification method, and
the system records who did it.

## 1. Staff verification checklist

In the review window, under each document: **How to check it is genuine** opens the steps for that document type
(`frontend/src/lib/verifyGuide.js`). After checking, staff type a short note (e.g. "Scanned QR in PhilSys Check")
and press **Mark as verified**. The document then shows "✓ Verified by <staff> on <date>", and it counts in the AI
Recommendations score. **Undo** removes it. Saved in `documents.staff_verified_at / staff_verified_by /
staff_verify_note`; `POST /api/documents/{id}/verify` (staff only; written to the Activity Log).

| Document | How it can be checked | Source |
|---|---|---|
| National ID (PhilID / ePhilID) | Scan the QR code with **PhilSys Check** (verify.philsys.gov.ph), free, by the PSA | PSA / PhilSys |
| PSA Birth Certificate | e-certificate: scan its QR code; printed copy: security paper (fibers, watermark, serial number); if in doubt request verification from the PSA | PSA |
| COR, Grades | Compare with the Registrar's enrollment list; ask the University Registrar | CSU Registrar |
| Indigency, Barangay Clearance | Barangay name, date, punong barangay's signature, dry seal; call the barangay hall | Issuing barangay |
| Income Tax Return | BIR form 1700 / 2316 with both signatures; compare with the income typed by the student | BIR / employer |
| Good Moral, Recommendation | Signer's name and position; contact the signer | Issuing office |

## 2. "Possible edit (not proof)" cautions

`ai-service/authenticity.py` reads hidden details **inside** the file during the AI check:
- PDF: the program that made or saved it (Creator / Producer) — e.g. an online PDF editor or Photoshop; the
  created vs. changed date (more than 5 minutes apart); how many times it was saved again (`%%EOF` markers;
  "fast web view" PDFs are allowed two).
- JPG / PNG: the "Software" field a photo editor writes.

They appear in the review window as a yellow **Possible edit (not proof)** box. They **never** change the document's
status. A genuine certificate can be saved in an editor, and a fake can show none of these signs. Tests:
`ai-service/tests/test_authenticity.py`.

## Sources

- PhilSys Check — https://verify.philsys.gov.ph
- PSA Serbilis / e-certificates and security paper — https://psa.gov.ph
- BIR forms 1700 and 2316 — https://www.bir.gov.ph

Check these pages before the defense: offices change their verification services.
