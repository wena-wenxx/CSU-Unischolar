<?php

namespace Database\Seeders\Support;

/**
 * Text of each SAMPLE document type used in the demo data.
 * All people, barangays and issuers are fictional; nothing here copies an
 * official form, seal or agency layout.
 */
class DemoDocuments
{
    public const COR = 'Certificate of Registration (COR)';
    public const GRADES = 'Certificate of Grades';
    public const INDIGENCY = 'Certificate of Indigency';
    public const BIRTH = 'Birth Certificate';
    public const VALID_ID = 'Valid ID';
    public const BARANGAY = 'Barangay Clearance';
    public const GOOD_MORAL = 'Certificate of Good Moral Character';
    public const ITR = "Parents' Income Tax Return";
    public const RECOMMENDATION = 'Recommendation Letter';

    // Requirement name -> file name used for uploads.
    public const FILE_NAMES = [
        self::COR => 'certificate-of-registration.pdf',
        self::GRADES => 'certificate-of-grades.pdf',
        self::INDIGENCY => 'certificate-of-indigency.pdf',
        self::BIRTH => 'birth-certificate.pdf',
        self::VALID_ID => 'valid-id.pdf',
        self::BARANGAY => 'barangay-clearance.pdf',
        self::GOOD_MORAL => 'certificate-of-good-moral-character.pdf',
        self::ITR => 'parents-income-tax-return.pdf',
        self::RECOMMENDATION => 'recommendation-letter.pdf',
    ];

    // Short help text shown to students for each requirement.
    public const DESCRIPTIONS = [
        self::COR => 'Current semester COR, signed by the Registrar.',
        self::GRADES => 'Grades for the previous semester, with GWA.',
        self::INDIGENCY => 'Issued by your barangay within the last 6 months.',
        self::BIRTH => 'Clear copy of your birth certificate.',
        self::VALID_ID => 'Student ID or any government-issued ID with your photo.',
        self::BARANGAY => 'Issued by your barangay within the last 6 months.',
        self::GOOD_MORAL => 'From the Guidance Office or your previous school.',
        self::ITR => "Latest income tax return of your parents or guardian (or certificate of tax exemption).",
        self::RECOMMENDATION => 'From a faculty member, coach or adviser who knows you.',
    ];

    private const SUBJECTS = [
        ['GE 101', 'Understanding the Self', 3, '1.50'],
        ['GE 102', 'Readings in Philippine History', 3, '1.75'],
        ['GE 103', 'Mathematics in the Modern World', 3, '1.25'],
        ['GE 104', 'Purposive Communication', 3, '1.50'],
        ['PE 102', 'Physical Fitness and Wellness', 2, '1.25'],
        ['NSTP 102', 'National Service Training Program 2', 3, '1.50'],
    ];

    /**
     * @param  array  $p  person: first, middle, last, student_id, course, year_level, college,
     *                    father, mother, birth_date, barangay
     * @return array [title, lines, issuer]
     */
    public static function content(string $requirement, array $p): array
    {
        $formal = strtoupper("{$p['last']}, {$p['first']} {$p['middle']}");
        $full = strtoupper("{$p['first']} {$p['middle']} {$p['last']}");
        $barangay = $p['barangay'] ?? 'Barangay Sample';
        $registrar = 'CSU UniScholar Demo - Sample University Registrar';
        $barangayOffice = "{$barangay}, Butuan City (fictional) - Office of the Punong Barangay";

        switch ($requirement) {
            case self::COR:
                $lines = [
                    '1st Semester, Academic Year 2026-2027',
                    "Name: {$formal}",
                    "Student ID No.: {$p['student_id']}",
                    "Course: {$p['course']}",
                    "Year Level: {$p['year_level']}",
                    'Status: Officially enrolled',
                    'Subjects enrolled:',
                ];
                foreach (self::SUBJECTS as [$code, $title, $units]) {
                    $lines[] = "   {$code}  {$title}  -  {$units} units";
                }
                $lines[] = 'Total units: 17';

                return ['CERTIFICATE OF REGISTRATION', $lines, $registrar];

            case self::GRADES:
                $lines = [
                    '2nd Semester, Academic Year 2025-2026',
                    "Name: {$formal}",
                    "Student ID No.: {$p['student_id']}",
                    "Course: {$p['course']}",
                    'Subject / Units / Final Grade / Remarks',
                ];
                foreach (self::SUBJECTS as [$code, $title, $units, $grade]) {
                    $lines[] = "   {$code}  {$title}  -  {$units} units  -  {$grade}  Passed";
                }
                $lines[] = 'General Weighted Average (GWA): ' . ($p['gwa'] ?? '1.50');

                return ['CERTIFICATE OF GRADES', $lines, $registrar];

            case self::VALID_ID:
                return ['STUDENT IDENTIFICATION CARD', [
                    "Name: {$full}",
                    "ID No.: {$p['student_id']}",
                    "Course: {$p['course']}",
                    '[ photo ]',
                    'Student signature: ____________________',
                    'Sample card for system testing only.',
                ], 'CSU UniScholar Demo - sample only'];

            case self::BIRTH:
                return ['CERTIFICATE OF LIVE BIRTH', [
                    'Sample Civil Registry - for testing only',
                    "Name of child: {$full}",
                    "Date of birth: {$p['birth_date']}",
                    'Place of birth: Sample Hospital, Butuan City',
                    'Mother: ' . strtoupper($p['mother']),
                    'Father: ' . strtoupper($p['father']),
                    'Recorded in the sample civil registry for system testing only.',
                ], 'Sample Civil Registry (fictional)'];

            case self::INDIGENCY:
                return ['CERTIFICATE OF INDIGENCY', [
                    'TO WHOM IT MAY CONCERN:',
                    "This is to certify that {$full} is a bona fide resident of {$barangay},",
                    'Butuan City, and belongs to an indigent family with a low income.',
                    'Issued upon request for scholarship application purposes.',
                    'Issued this 1st day of September 2026.',
                ], $barangayOffice];

            case self::BARANGAY:
                return ['BARANGAY CLEARANCE', [
                    'TO WHOM IT MAY CONCERN:',
                    "This is to certify that {$full} is a resident of {$barangay}, Butuan City,",
                    'and has no derogatory record on file in this barangay as of this date.',
                    'Issued upon request for whatever legal purpose it may serve.',
                    'Issued this 1st day of September 2026.',
                ], $barangayOffice];

            case self::GOOD_MORAL:
                return ['CERTIFICATE OF GOOD MORAL CHARACTER', [
                    'TO WHOM IT MAY CONCERN:',
                    "This is to certify that {$full} has shown good moral character",
                    'and has no record of disciplinary action in this office.',
                    'Issued this 1st day of September 2026.',
                    'Guidance Office (sample)',
                ], 'CSU UniScholar Demo - Sample Guidance Office'];

            case self::ITR:
                $income = number_format($p['family_income'] ?? 96000, 2);

                return ['ANNUAL INCOME TAX RETURN (SAMPLE)', [
                    'Taxable year: 2025',
                    'Taxpayer: ' . strtoupper($p['father']),
                    'Spouse: ' . strtoupper($p['mother']),
                    "Gross compensation income: PHP {$income}",
                    'Taxable income: PHP 0.00 (minimum wage earner)',
                    'Tax due: PHP 0.00',
                    'Sample return for system testing only - not filed with any agency.',
                ], 'Sample tax form (fictional)'];

            case self::RECOMMENDATION:
                return ['LETTER OF RECOMMENDATION', [
                    'To the Scholarship Committee:',
                    "I am pleased to recommend {$full}, currently in {$p['year_level']} of",
                    "{$p['course']}, for your scholarship program.",
                    'I have found this applicant diligent, respectful and active in class and in school activities.',
                    'Respectfully yours,',
                    'Faculty Adviser (sample)',
                ], 'CSU UniScholar Demo - sample letter'];
        }

        return ['DOCUMENT', [$full], 'CSU UniScholar Demo'];
    }

    /** Plain text of a document, the way a PDF text reader would see it. */
    public static function text(string $title, array $lines, string $issuer): string
    {
        return implode("\n", array_merge(
            ['SAMPLE - NOT OFFICIAL',
                'SAMPLE FOR CSU UNISCHOLAR TESTING ONLY - NOT AN OFFICIAL DOCUMENT - FICTIONAL PERSON',
                $issuer, $title],
            $lines
        ));
    }
}
