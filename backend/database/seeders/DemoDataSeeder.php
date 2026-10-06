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
    private const PRIVATE_ALL = [Doc::COR, Doc::GRADES, Doc::BIRTH, Doc::INDIGENCY, Doc::VALID_ID, Doc::BARANGAY, Doc::GOOD_MORAL, Doc::ITR, Doc::RECOMMENDATION];
    private const PRIVATE_MERIT = [Doc::COR, Doc::GRADES, Doc::BIRTH, Doc::VALID_ID, Doc::BARANGAY, Doc::GOOD_MORAL, Doc::ITR, Doc::RECOMMENDATION];

    public const CMSP = 'CHED Merit Scholarship Program (CMSP)';
    public const TES = 'Tertiary Education Subsidy (TES)';
    public const DOST = 'DOST-SEI Undergraduate Scholarship';
    public const CSU_SA = 'CSU Student Assistance Scholarship';
    public const CHOIR = 'CSU Cultural Grant (Choir)';
    public const DANCE = 'CSU Cultural Grant (Dance Troupe)';
    public const KAYAM = 'CSU Cultural Grant (Kayam Ethno Band)';
    public const ATHLETIC = 'CSU Athletic Grant';
    public const BUTUAN = 'Butuan City Scholarship Program';

    /**
     * 20 programs. amount = demo amount per semester (PHP).
     * status: active = open for applications; closed = deadline passed;
     *         inactive = not offered this academic year.
     */
    public const PROGRAMS = [
        // Government
        [self::CMSP, 'Commission on Higher Education (CHED)', 'government', 20000, '2026-06-15', '2026-08-31', 'closed',
            'Merit-based scholarship for students with high academic performance from qualified low-income households.', self::GOV],
        [self::TES, 'Commission on Higher Education (CHED) - UniFAST', 'government', 20000, '2026-07-01', '2026-10-31', 'active',
            'Grant-in-aid for financially disadvantaged students enrolled in public higher education institutions.',
            [Doc::COR, Doc::GRADES, Doc::BIRTH, Doc::INDIGENCY, Doc::VALID_ID, Doc::ITR, Doc::BARANGAY, Doc::GOOD_MORAL]],
        [self::DOST, 'Department of Science and Technology - Science Education Institute (DOST-SEI)', 'government', 40000, '2026-06-01', '2026-08-14', 'closed',
            'Scholarship for students taking priority science, technology, engineering and mathematics courses.',
            [Doc::COR, Doc::GRADES, Doc::BIRTH, Doc::VALID_ID, Doc::GOOD_MORAL, Doc::ITR, Doc::RECOMMENDATION, Doc::BARANGAY]],

        // CSU-funded
        [self::CSU_SA, 'Caraga State University', 'csu', 5000, '2026-07-15', '2026-10-30', 'active',
            'University-funded assistance for financially challenged students in good academic standing.',
            [Doc::COR, Doc::GRADES, Doc::INDIGENCY, Doc::VALID_ID, Doc::GOOD_MORAL, Doc::ITR, Doc::BARANGAY, Doc::RECOMMENDATION]],
        [self::CHOIR, 'Caraga State University', 'csu', 6000, '2026-08-01', '2026-11-15', 'active',
            'Grant for active members of the CSU Choir.', self::TALENT],
        [self::DANCE, 'Caraga State University', 'csu', 6000, '2026-08-01', '2026-11-15', 'active',
            'Grant for active members of the CSU Dance Troupe.', self::TALENT],
        [self::KAYAM, 'Caraga State University', 'csu', 6000, '2026-08-01', '2026-11-15', 'active',
            'Grant for active members of the Kayam Ethno Band.', self::TALENT],
        [self::ATHLETIC, 'Caraga State University', 'csu', 8000, '2026-08-01', '2026-11-15', 'active',
            'Grant for varsity athletes representing the university.', self::TALENT],

        // LGU
        [self::BUTUAN, 'City Government of Butuan', 'lgu', 10000, '2026-07-01', '2026-10-31', 'active',
            'Scholarship for qualified bona fide residents of Butuan City.', self::GOV],

        // Private / foundation
        ['SM College Scholarship', 'SM Foundation', 'private', 15000, '2026-04-01', '2026-06-30', 'closed',
            'College scholarship for deserving students from low-income families.', self::PRIVATE_ALL],
        ['Ayala U-Go Scholarship', 'Ayala Foundation', 'private', 25000, '2026-08-01', '2026-11-30', 'active',
            'Scholarship for academically promising students who want to make a difference in their communities.', self::PRIVATE_ALL],
        ['BPI Science Scholarship', 'BPI Foundation', 'private', 30000, '2026-08-15', '2026-11-15', 'active',
            'Scholarship for students in science and mathematics programs.', self::PRIVATE_MERIT],
        ['Metrobank Scholarship', 'Metrobank Foundation', 'private', 35000, '2026-07-15', '2026-10-31', 'active',
            'Scholarship for high-achieving students in selected degree programs.', self::PRIVATE_MERIT],
        ['Aboitiz Future Leaders Scholarship', 'Aboitiz Foundation', 'private', 30000, '2026-08-01', '2026-11-15', 'active',
            'Scholarship for students with strong leadership potential.', self::PRIVATE_MERIT],
        ['Megaworld Scholarship', 'Megaworld Foundation', 'private', 20000, '2026-07-01', '2026-10-31', 'active',
            'Scholarship for financially challenged students with good grades.', self::PRIVATE_ALL],
        ['DMCI Homes Scholarship', 'DMCI Homes', 'private', 25000, '2026-08-01', '2026-11-30', 'active',
            'Scholarship for engineering and architecture-related programs.', self::PRIVATE_MERIT],
        ['Petron Bataan Refinery Scholarship', 'Petron Foundation', 'private', 15000, '2026-06-01', '2026-07-31', 'inactive',
            'Not offered to CSU students this academic year.', self::PRIVATE_ALL],
        ['San Miguel Foundation Scholarship', 'San Miguel Foundation', 'private', 20000, '2026-07-15', '2026-11-15', 'active',
            'Scholarship for deserving students from partner communities.', self::PRIVATE_ALL],
        ['Manila Water Foundation Scholarship', 'Manila Water Foundation', 'private', 15000, '2026-08-01', '2026-11-30', 'active',
            'Scholarship for students in environment- and health-related programs.', self::PRIVATE_ALL],
        ['Unilab Foundation Scholarship', 'United Laboratories (Unilab Foundation)', 'private', 25000, '2026-08-01', '2026-11-30', 'active',
            'Scholarship for students in health sciences and STEM programs.', self::PRIVATE_MERIT],
    ];

    public function run(): void
    {
        $this->seedPrograms();
        $this->seedNamedScenarios();
    }

    private function seedPrograms(): void
    {
        foreach (self::PROGRAMS as [$name, $provider, $category, $amount, $start, $end, $status, $description, $requirements]) {
            $scholarship = Scholarship::firstOrCreate(
                ['name' => $name],
                [
                    'provider' => $provider,
                    'category' => $category,
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
     *  Ana    DOST-SEI  under review; also a COMPLETED past scholarship with processed payroll (Data Bank history)
     *  Pedro  Butuan    needs action (OAS asked for a clearer document)
     *  Liza   CSU SA    rejected by the agency (applies live to the CSU Choir grant during the demo)
     *  Carlo  Kayam     draft, only 1 of 6 documents uploaded
     *  Rosa   CSU SA    complete (forwarded); her COR belongs to another person (AI should flag the name)
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
        $this->scenario('2026-00003', self::DOST, [
            'status' => 'under_review', 'submitted_days_ago' => 60,
        ]);

        $this->scenario('2026-00004', self::BUTUAN, [
            'status' => 'needs_action', 'submitted_days_ago' => 9,
            'remarks' => 'Your Barangay Clearance is hard to read. Please upload a clearer copy.',
        ]);

        $this->scenario('2026-00005', self::CSU_SA, [
            'status' => 'rejected', 'submitted_days_ago' => 25,
            'remarks' => 'Not included in the agency\'s approved list for this semester.',
        ]);

        $this->scenario('2026-00006', self::KAYAM, [
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
