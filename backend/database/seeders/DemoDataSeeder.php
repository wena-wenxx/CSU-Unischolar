<?php

namespace Database\Seeders;

use App\Models\Application;
use App\Models\Document;
use App\Models\PayrollRecord;
use App\Models\ScholarRecord;
use App\Models\Scholarship;
use App\Models\ScholarshipRequirement;
use App\Models\Student;
use Database\Seeders\Support\DemoDocuments as Doc;
use Database\Seeders\Support\DemoPdf;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;

/**
 * Scholarship programs + the 10 NAMED demo scenarios used in the test plan
 * and the panel demo (student1 ... student10).
 *
 * FICTIONAL DEMO DATA ONLY. Every person is fictional and every attached
 * file is a watermarked SAMPLE from database/seeders/demo-files/.
 *
 * Program amounts and application dates are ILLUSTRATIVE demo values,
 * not official figures from CHED, DOST, CSU, the LGU or any foundation.
 * Replace them with official figures (Scholarships page, staff login)
 * before using the system for real.
 *
 * The AI check is NOT pre-run for the named scenarios: press "Run AI check"
 * during the demo so the panel sees real results.
 *
 * Safe to run more than once (it never creates duplicates):
 *   php artisan db:seed --class=DemoDataSeeder
 */
class DemoDataSeeder extends Seeder
{
    // Requirement sets (names live in Support/DemoDocuments.php)
    private const GOV = [Doc::COR, Doc::GRADES, Doc::BIRTH, Doc::INDIGENCY, Doc::VALID_ID, Doc::GOOD_MORAL, Doc::ITR, Doc::BARANGAY];
    private const TALENT = [Doc::COR, Doc::GRADES, Doc::VALID_ID, Doc::BIRTH, Doc::GOOD_MORAL, Doc::RECOMMENDATION];

    // The scholarship programs listed by the CSU Office of Admission and
    // Scholarship (OAS). Names follow the OAS list.
    public const CMSP = 'CHED Merit Scholarship Program (CMSP)';
    public const TDP_TES = 'CHED Tulong-Dunong Program (TDP-TES)';
    public const TES = 'CHED Tertiary Education Subsidy (TES)';
    public const TDP_SUC = 'CHED TDP - State Universities and Colleges (TDP-SUC)';
    public const SA = 'Student Assistantship (SA) Program';
    public const CULTURE = 'Culture and Arts';
    public const LANDBANK = 'Iskolar ng Landbank Program';
    public const DA_ACEF = 'Department of Agriculture - Agricultural Competitiveness Enhancement Fund (DA-ACEF)';
    public const GIAHEP = 'Agricultural Competitiveness Enhancement Fund - Grant-in-Aid for Higher Education Program (ACEF-GIAHEP)';
    public const DOST = 'Department of Science and Technology (DOST)';
    public const BAYUGAN = 'Local Government Unit-Bayugan (LGU-Bayugan) Graduate School Scholarship Program';
    public const NGCP = 'The National Grid Corporation of the Philippines (NGCP) Scholarship Program';
    public const MEKONG = 'Enfants du Mekong Scholarship';

    // Older names used by the test plan (same programs).
    public const CSU_SA = self::SA;

    private const AGENCY_DIRECT_NOTE = 'Apply directly to the scholarship-giving agency; requirements and allowances are handled by its office. The OAS posts announcements and updates when the agency requests it.';

    /**
     * [name, short name, provider, category, mode, amount, start, end, status, description, requirements]
     * mode: oas = apply in ScholarGuide; agency_direct = apply at the agency (no requirements here).
     * Amounts and dates of the OAS-processed programs are DEMO values: replace
     * them with the official figures (Scholarships page) before real use.
     */
    public const PROGRAMS = [
        // CHED-funded (processed through the OAS)
        [self::CMSP, 'CMSP', 'Commission on Higher Education (CHED)', 'ched', 'oas', 20000, '2026-06-15', '2026-08-31', 'closed',
            'CHED-funded merit scholarship processed through the OAS, with Full SSP and Half SSP grantees.', self::GOV],
        [self::TDP_TES, 'TDP-TES', 'Commission on Higher Education (CHED)', 'ched', 'oas', 7500, '2026-07-01', '2026-10-31', 'active',
            'CHED-funded financial assistance processed through the OAS.',
            [Doc::COR, Doc::GRADES, Doc::VALID_ID, Doc::INDIGENCY, Doc::ITR, Doc::BIRTH]],
        [self::TES, 'TES', 'Commission on Higher Education (CHED)', 'ched', 'oas', 20000, '2026-07-01', '2026-10-31', 'active',
            'CHED-funded tertiary education subsidy processed through the OAS.',
            [Doc::COR, Doc::GRADES, Doc::BIRTH, Doc::INDIGENCY, Doc::VALID_ID, Doc::ITR, Doc::BARANGAY, Doc::GOOD_MORAL]],
        [self::TDP_SUC, 'TDP-SUC', 'Commission on Higher Education (CHED)', 'ched', 'oas', 7500, '2026-07-15', '2026-11-15', 'active',
            'CHED-funded Tulong-Dunong assistance for students of state universities and colleges, processed through the OAS.',
            [Doc::COR, Doc::GRADES, Doc::VALID_ID, Doc::INDIGENCY, Doc::ITR]],

        // University-funded scholarship / financial assistance
        [self::SA, 'SA', 'Caraga State University', 'csu', 'oas', 5000, '2026-07-15', '2026-10-30', 'active',
            'University-funded student assistantship program.',
            [Doc::COR, Doc::GRADES, Doc::INDIGENCY, Doc::VALID_ID, Doc::GOOD_MORAL, Doc::ITR, Doc::BARANGAY, Doc::RECOMMENDATION]],
        [self::CULTURE, 'Culture & Arts', 'Caraga State University', 'csu', 'oas', 6000, '2026-08-01', '2026-11-15', 'active',
            'University-funded financial assistance for students in CSU culture and arts groups.', self::TALENT],

        // Other government-funded (agency-direct)
        [self::LANDBANK, 'Landbank', 'Land Bank of the Philippines', 'government', 'agency_direct', null, null, null, 'active', self::AGENCY_DIRECT_NOTE, []],
        [self::DA_ACEF, 'DA-ACEF', 'Department of Agriculture', 'government', 'agency_direct', null, null, null, 'active', self::AGENCY_DIRECT_NOTE, []],
        [self::GIAHEP, 'ACEF-GIAHEP', 'Department of Agriculture', 'government', 'agency_direct', null, null, null, 'active', self::AGENCY_DIRECT_NOTE, []],
        [self::DOST, 'DOST', 'Department of Science and Technology', 'government', 'agency_direct', null, null, null, 'active', self::AGENCY_DIRECT_NOTE, []],
        [self::BAYUGAN, 'LGU-Bayugan', 'Local Government Unit of Bayugan', 'government', 'agency_direct', null, null, null, 'active', self::AGENCY_DIRECT_NOTE, []],

        // Private-funded (agency-direct)
        [self::NGCP, 'NGCP', 'National Grid Corporation of the Philippines', 'private', 'agency_direct', null, null, null, 'active', self::AGENCY_DIRECT_NOTE, []],
        [self::MEKONG, 'Enfants du Mekong', 'Enfants du Mekong', 'private', 'agency_direct', null, null, null, 'active', self::AGENCY_DIRECT_NOTE, []],
    ];

    public function run(): void
    {
        $this->seedPrograms();
        $this->seedNamedScenarios();
    }

    private function seedPrograms(): void
    {
        foreach (self::PROGRAMS as [$name, $short, $provider, $category, $mode, $amount, $start, $end, $status, $description, $requirements]) {
            $scholarship = Scholarship::firstOrCreate(
                ['name' => $name],
                [
                    'short_name' => $short,
                    'provider' => $provider,
                    'category' => $category,
                    'application_mode' => $mode,
                    'description' => $description,
                    'amount' => $amount, // demo amount per semester
                    'application_start' => $start,
                    'application_end' => $end,
                    'status' => $status,
                ]
            );

            foreach ($requirements as $requirementName) {
                ScholarshipRequirement::firstOrCreate(
                    ['scholarship_id' => $scholarship->id, 'name' => $requirementName],
                    ['is_required' => true, 'description' => Doc::DESCRIPTIONS[$requirementName] ?? null]
                );
            }
        }
    }

    /**
     *  Juan   CMSP      approved + enrollment verified -> ready to TAG as grantee
     *  Maria  TES       submitted; her "Indigency" upload is really a Barangay Clearance (AI should flag it)
     *  Ana    TDP-SUC   under review; also a COMPLETED past SA scholarship with processed payroll (Data Bank history)
     *  Pedro  TDP-TES   needs action (OAS asked for a clearer document)
     *  Liza   SA        rejected (applies live to Culture and Arts during the demo)
     *  Carlo  Culture   draft, only 1 of 6 documents uploaded
     *  Rosa   SA        complete (forwarded); her COR belongs to another person (AI should flag the name)
     *  Mark   TES       approved, enrollment NOT yet verified -> demo "Verify enrollment"
     *  Jose   TES       active grantee with a READY payroll entry
     *  Grace  CMSP      active grantee with no payroll yet -> demo batch payroll
     */
    private function seedNamedScenarios(): void
    {
        $this->scenario('2026-00001', self::CMSP, [
            'status' => 'approved', 'submitted_days_ago' => 40, 'verified_days_ago' => 2,
        ]);

        $this->scenario('2026-00002', self::TES, [
            'status' => 'submitted', 'submitted_days_ago' => 3,
            'swap' => [Doc::INDIGENCY => ['file' => 'barangay-clearance.pdf', 'as' => 'barangay-clearance.pdf']],
        ]);

        $this->scenario('2026-00003', self::CSU_SA, [
            'status' => 'approved', 'submitted_days_ago' => 400, 'verified_days_ago' => 380,
            'grantee' => ['status' => 'completed', 'has_atm' => true, 'tagged_days_ago' => 375,
                'payroll' => ['period' => '2nd Semester AY 2025-2026', 'amount' => 5000, 'status' => 'processed']],
        ]);
        $this->scenario('2026-00003', self::TDP_SUC, [
            'status' => 'under_review', 'submitted_days_ago' => 60,
        ]);

        $this->scenario('2026-00004', self::TDP_TES, [
            'status' => 'needs_action', 'submitted_days_ago' => 9,
            'remarks' => 'Your Certificate of Indigency is hard to read. Please upload a clearer copy.',
        ]);

        $this->scenario('2026-00005', self::CSU_SA, [
            'status' => 'rejected', 'submitted_days_ago' => 25,
            'remarks' => 'Not included in the agency\'s approved list for this semester.',
        ]);

        $this->scenario('2026-00006', self::CULTURE, [
            'status' => 'draft', 'only' => [Doc::COR],
        ]);

        $this->scenario('2026-00007', self::CSU_SA, [
            'status' => 'complete', 'submitted_days_ago' => 12,
            'swap' => [Doc::COR => ['shared' => 'cor-of-a-different-person.pdf', 'as' => 'COR-scan.pdf']],
        ]);

        $this->scenario('2026-00008', self::TES, [
            'status' => 'approved', 'submitted_days_ago' => 20,
        ]);

        $this->scenario('2026-00009', self::TES, [
            'status' => 'approved', 'submitted_days_ago' => 30, 'verified_days_ago' => 10,
            'grantee' => ['status' => 'active', 'has_atm' => true, 'tagged_days_ago' => 9,
                'payroll' => ['period' => '1st Semester AY 2026-2027', 'amount' => 20000, 'status' => 'ready']],
        ]);

        $this->scenario('2026-00010', self::CMSP, [
            'status' => 'approved', 'submitted_days_ago' => 45, 'verified_days_ago' => 8,
            'grantee' => ['status' => 'active', 'has_atm' => false, 'tagged_days_ago' => 7],
        ]);
    }

    private function scenario(string $studentId, string $scholarshipName, array $s): void
    {
        $student = Student::where('student_id', $studentId)->first();
        $scholarship = Scholarship::with('requirements')->where('name', $scholarshipName)->first();

        if (!$student || !$scholarship) {
            $this->command?->warn("Skipped demo scenario for {$studentId} ({$scholarshipName}): run StudentSeeder first.");
            return;
        }

        if (Application::where('student_id', $student->id)->where('scholarship_id', $scholarship->id)->exists()) {
            return;
        }

        $verified = isset($s['verified_days_ago']);

        $application = Application::create([
            'student_id' => $student->id,
            'scholarship_id' => $scholarship->id,
            'status' => $s['status'],
            'remarks' => $s['remarks'] ?? null,
            'submitted_at' => isset($s['submitted_days_ago']) ? now()->subDays($s['submitted_days_ago']) : null,
            'enrollment_verified' => $verified,
            'enrollment_verified_at' => $verified ? now()->subDays($s['verified_days_ago']) : null,
        ]);

        $this->attachDocuments($application, $scholarship, $student, $s);

        if (isset($s['grantee'])) {
            $g = $s['grantee'];

            $record = ScholarRecord::firstOrCreate(
                ['student_id' => $student->id, 'scholarship_id' => $scholarship->id],
                [
                    'status' => $g['status'],
                    'currently_enrolled' => $g['status'] === 'active',
                    'has_atm' => $g['has_atm'],
                    'grantee_tagged_at' => now()->subDays($g['tagged_days_ago']),
                ]
            );

            if (isset($g['payroll'])) {
                PayrollRecord::firstOrCreate(
                    ['scholar_record_id' => $record->id, 'period' => $g['payroll']['period']],
                    [
                        'amount' => $g['payroll']['amount'], // demo amount
                        'bank_atm_status' => $g['has_atm'] ? 'Yes' : 'No',
                        'status' => $g['payroll']['status'],
                    ]
                );
            }
        }
    }

    private function attachDocuments(Application $application, Scholarship $scholarship, Student $student, array $s): void
    {
        $folder = $this->studentFolder($student->student_id);

        foreach ($scholarship->requirements as $requirement) {

            if (isset($s['only']) && !in_array($requirement->name, $s['only'], true)) {
                continue;
            }

            $swap = $s['swap'][$requirement->name] ?? null;
            $fileName = $swap['file'] ?? (Doc::FILE_NAMES[$requirement->name] ?? 'document.pdf');

            if ($swap && isset($swap['shared'])) {
                $source = database_path('seeders/demo-files/' . $swap['shared']);
                $fileName = $swap['shared'];
            } else {
                $source = $folder ? $folder . '/' . $fileName : null;
            }

            // Use the prepared sample file; if it is missing, generate one.
            if ($source && is_file($source)) {
                $pdf = file_get_contents($source);
            } else {
                [$title, $lines, $issuer] = Doc::content($requirement->name, [
                    'first' => $student->first_name, 'middle' => $student->middle_name ?? '',
                    'last' => $student->last_name, 'student_id' => $student->student_id,
                    'course' => $student->course, 'year_level' => $student->year_level,
                    'college' => $student->college, 'father' => "Ramon {$student->last_name}",
                    'mother' => "Elena {$student->last_name}", 'birth_date' => 'January 15, 2005',
                ]);
                $pdf = DemoPdf::make($title, $lines, $issuer);
            }

            $path = "documents/demo/{$student->student_id}/{$application->id}-{$fileName}";
            Storage::disk('public')->put($path, $pdf);

            Document::create([
                'application_id' => $application->id,
                'scholarship_requirement_id' => $requirement->id,
                'original_filename' => $swap['as'] ?? $fileName,
                'file_path' => $path,
                'status' => 'uploaded',
            ]);
        }
    }

    private function studentFolder(string $studentId): ?string
    {
        $matches = glob(database_path("seeders/demo-files/{$studentId}-*"), GLOB_ONLYDIR);

        return $matches[0] ?? null;
    }
}
