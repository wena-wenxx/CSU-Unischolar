/*
  How OAS staff can confirm that a document is genuine. The AI cannot prove
  this: it only reads the text and points out possible problems. These steps
  follow what the issuing offices themselves offer (PSA, PhilSys Check, the
  CSU Registrar, the barangay, BIR / employer).
*/
const COMMON = "Open the file: the name and Student ID must match the student, and the date must be recent.";

export const VERIFY_GUIDE = {
  "Certificate of Registration (COR)": [
    COMMON,
    "Compare with the Registrar's enrollment list (Enrollment page) for this semester: same Student ID, course and year level.",
    "If in doubt, ask the University Registrar to confirm the student's registration.",
  ],
  "Certificate of Grades": [
    COMMON,
    "Check that the semester, subjects and GWA look complete and match the student's course.",
    "If in doubt, ask the Registrar (or the college) to confirm the grades.",
  ],
  "Valid ID": [
    "National ID (PhilID / ePhilID): scan its QR code with PhilSys Check at verify.philsys.gov.ph (free, by the PSA). The details shown must match the card.",
    "Other IDs: compare the photo and name with the student in person at the OAS, and check that it is not expired.",
  ],
  "Birth Certificate": [
    "PSA e-certificate: scan its QR code to check it online.",
    "Printed PSA copy: security paper with red and blue fibers, a watermark and a serial number. Looking at it is only a first check.",
    "If in doubt, ask the student for a fresh PSA copy, or request verification from the PSA.",
  ],
  "Certificate of Indigency": [
    COMMON,
    "Check the barangay name, the date, the punong barangay's signature and the dry seal.",
    "If in doubt, call or visit the barangay hall that issued it.",
  ],
  "Barangay Clearance": [
    COMMON,
    "Check the barangay name, the date, the signature and the dry seal.",
    "If in doubt, call or visit the barangay hall that issued it.",
  ],
  "Certificate of Good Moral Character": [
    COMMON,
    "Check the school or office that issued it, the signatory and the date.",
    "If in doubt, call the issuing guidance office to confirm.",
  ],
  "Parents' Income Tax Return": [
    "It should be the BIR form (e.g. 1700, or 2316 from the employer) with the parent's name and both signatures.",
    "Compare the income with the family income the student typed on My Profile.",
    "If in doubt, ask for the employer's certificate or a BIR-received copy.",
  ],
  "Recommendation Letter": [
    COMMON,
    "Check the signer's name and position; if in doubt, contact the signer.",
  ],
};

export function verifySteps(typeName) {
  return VERIFY_GUIDE[typeName] || [COMMON, "If in doubt, confirm with the office that issued it."];
}
