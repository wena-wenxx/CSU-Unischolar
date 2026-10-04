<?php

namespace Database\Seeders;

use App\Models\Application;
use App\Models\Document;
use App\Models\PayrollRecord;
use App\Models\ScholarRecord;
use App\Models\Scholarship;
use App\Models\ScholarshipRequirement;
use App\Models\Student;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;

/**
 * Demo scholarship programs, requirements and applications.
 *
 * Synthetic data for development and the capstone demo only: every person
 * is fictional and every attached file is a watermarked SAMPLE from
 * database/seeders/demo-files/.
 *
 * Program amounts and application dates are left empty on purpose: fill them
 * in with OAS's official figures from the Scholarships page. The payroll
 * amounts below are DEMO values, not official stipend amounts.
 *
 * The AI check is NOT pre-run: press "Run AI check" during the demo so the
 * panel sees real results.
 *
 * Safe to run more than once (it never creates duplicates):
 *   php artisan db:seed --class=DemoDataSeeder
 */
class DemoDataSeeder extends Seeder
{
    // Requirement names. Keep these EXACT: the AI service uses them to
    // decide which kind of document to expect.
    private const COR = 'Certificate of Registration (COR)';
    private const GRADES = 'Certificate of Grades';
    private const INDIGENCY = 'Certificate of Indigency';
    private const BIRTH = 'Birth Certificate';
    private const VALID_ID = 'Valid ID';
    private const BARANGAY = 'Barangay Clearance';

    // Requirement name -> sample file name in each student's demo folder.
    private const FILES = [
        self::COR => 'certificate-of-registration.pdf',
        self::GRADES => 'certificate-of-grades.pdf',
        self::INDIGENCY => 'certificate-of-indigency.pdf',
        self::BIRTH => 'birth-certificate.pdf',
        self::VALID_ID => 'valid-id.pdf',
        self::BARANGAY => 'barangay-clearance.pdf',
    ];

    private const CSU_SA = 'CSU Student Assistance Scholarship';
    private const CMSP = 'CHED Merit Scholarship Program (CMSP)';
    private const TES = 'Tertiary Education Subsidy (TES)';
    private const DOST = 'DOST-SEI Scholarship';
    private const CULTURAL = 'CSU Cultural Grant (Choir / Dance Troupe / Kayam Ethno Band)';
    private const LGU = 'LGU Butuan City Scholarship';

    public function run(): void
    {
        $this->seedPrograms();
        $this->seedApplications();
    }

    private function seedPrograms(): void
    {
        $programs = [
            [
                'name' => self::CSU_SA,
                'provider' => 'Caraga State University',
                'description' => 'University-funded assistance for financially challenged students in good academic standing.',
                'requirements' => [self::COR, self::GRADES, self::INDIGENCY, self::VALID_ID],
            ],
            [
                'name' => self::CMSP,
                'provider' => 'Commission on Higher Education (CHED)',
                'description' => 'Merit-based scholarship for students with high academic performance from qualified low-income households.',
                'requirements' => [self::COR, self::GRADES, self::BIRTH, self::INDIGENCY, self::VALID_ID],
            ],
            [
                'name' => self::TES,
                'provider' => 'CHED - UniFAST',
                'description' => 'Grant-in-aid for financially disadvantaged students enrolled in public higher education institutions.',
                'requirements' => [self::COR, self::BIRTH, self::INDIGENCY, self::VALID_ID],
            ],
            [
                'name' => self::DOST,
                'provider' => 'Department of Science and Technology - Science Education Institute',
                'description' => 'Scholarship for students taking priority science, technology, engineering and mathematics courses.',
                'requirements' => [self::COR, self::GRADES, self::BIRTH, self::VALID_ID],
            ],
            [
                'name' => self::CULTURAL,
                'provider' => 'Caraga State University',
                'description' => 'Grant for active members of the CSU Choir, Dance Troupe or Kayam Ethno Band.',
                'requirements' => [self::COR, self::GRADES, self::VALID_ID],
            ],
            [
                'name' => self::LGU,
                'provider' => 'City Government of Butuan',
                'description' => 'Scholarship for qualified bona fide residents of Butuan City.',
                'requirements' => [self::COR, self::GRADES, self::BARANGAY, self::INDIGENCY, self::VALID_ID],
            ],
        ];

        foreach ($programs as $program) {
            $scholarship = Scholarship::firstOrCreate(
                ['name' => $program['name']],
                [
                    'provider' => $program['provider'],
                    'description' => $program['description'],
                    'status' => 'active',
                ]
            );

            foreach ($program['requirements'] as $requirementName) {
                ScholarshipRequirement::firstOrCreate(
                    ['scholarship_id' => $scholarship->id, 'name' => $requirementName],
                    ['is_required' => true]
                );
            }
        }
    }

    /**
     * One scenario per demo student, so every step of the OAS workflow
     * can be shown live:
     *
     *  Juan   CMSP      approved + enrollment verified -> ready to TAG as grantee
     *  Maria  TES       submitted; her "Indigency" upload is really a Barangay Clearance (AI should flag it)
     *  Ana    DOST-SEI  under review; also a COMPLETED past scholarship with processed payroll (Data Bank history)
     *  Pedro  LGU       needs action (OAS asked for a clearer document)
     *  Liza   CSU SA    rejected by the agency
     *  Carlo  Cultural  draft, only 1 of 3 documents uploaded
     *  Rosa   CSU SA    complete (forwarded); her COR belongs to another person (AI should flag the name)
     *  Mark   TES       approved, enrollment NOT yet verified -> demo "Verify enrollment"
     *  Jose   TES       active grantee with a READY payroll entry
     *  Grace  CMSP      active grantee with no payroll yet -> demo batch payroll
     */
    private function seedApplications(): void
    {
        $this->scenario('2026-00001', self::CMSP, [
            'status' => 'approved', 'submitted_days_ago' => 18, 'verified_days_ago' => 2,
        ]);

        $this->scenario('2026-00002', self::TES, [
            'status' => 'submitted', 'submitted_days_ago' => 3,
            'swap' => [self::INDIGENCY => ['file' => 'barangay-clearance.pdf', 'as' => 'barangay-clearance.pdf']],
        ]);

        $this->scenario('2026-00003', self::CSU_SA, [
            'status' => 'approved', 'submitted_days_ago' => 400, 'verified_days_ago' => 380,
            'grantee' => ['status' => 'completed', 'has_atm' => true, 'tagged_days_ago' => 375,
                'payroll' => ['period' => '2nd Semester AY 2025-2026', 'amount' => 5000, 'status' => 'processed']],
        ]);
        $this->scenario('2026-00003', self::DOST, [
            'status' => 'under_review', 'submitted_days_ago' => 6,
        ]);

        $this->scenario('2026-00004', self::LGU, [
            'status' => 'needs_action', 'submitted_days_ago' => 9,
            'remarks' => 'Your Barangay Clearance is hard to read. Please upload a clearer copy.',
        ]);

        $this->scenario('2026-00005', self::CSU_SA, [
            'status' => 'rejected', 'submitted_days_ago' => 25,
            'remarks' => 'Not included in the agency\'s approved list for this semester.',
        ]);

        $this->scenario('2026-00006', self::CULTURAL, [
            'status' => 'draft', 'only' => [self::COR],
        ]);

        $this->scenario('2026-00007', self::CSU_SA, [
            'status' => 'complete', 'submitted_days_ago' => 12,
            'swap' => [self::COR => ['shared' => 'cor-of-a-different-person.pdf', 'as' => 'COR-scan.pdf']],
        ]);

        $this->scenario('2026-00008', self::TES, [
            'status' => 'approved', 'submitted_days_ago' => 20,
        ]);

        $this->scenario('2026-00009', self::TES, [
            'status' => 'approved', 'submitted_days_ago' => 30, 'verified_days_ago' => 10,
            'grantee' => ['status' => 'active', 'has_atm' => true, 'tagged_days_ago' => 9,
                'payroll' => ['period' => '1st Semester AY 2026-2027', 'amount' => 5000, 'status' => 'ready']],
        ]);

        $this->scenario('2026-00010', self::CMSP, [
            'status' => 'approved', 'submitted_days_ago' => 28, 'verified_days_ago' => 8,
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

        // Never duplicate: if this application already exists, leave it alone.
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

        $this->attachDocuments($application, $scholarship, $studentId, $s);

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
                        'amount' => $g['payroll']['amount'], // demo value only
                        'bank_atm_status' => $g['has_atm'] ? 'Yes' : 'No',
                        'status' => $g['payroll']['status'],
                    ]
                );
            }
        }
    }

    private function attachDocuments(Application $application, Scholarship $scholarship, string $studentId, array $s): void
    {
        $folder = $this->studentFolder($studentId);

        foreach ($scholarship->requirements as $requirement) {

            if (isset($s['only']) && !in_array($requirement->name, $s['only'], true)) {
                continue;
            }

            $swap = $s['swap'][$requirement->name] ?? null;

            if ($swap && isset($swap['shared'])) {
                $source = database_path('seeders/demo-files/' . $swap['shared']);
            } else {
                $file = $swap['file'] ?? (self::FILES[$requirement->name] ?? null);
                $source = $folder && $file ? $folder . '/' . $file : null;
            }

            if (!$source || !is_file($source)) {
                $this->command?->warn("Demo file missing for {$studentId}: {$requirement->name}");
                continue;
            }

            $originalName = $swap['as'] ?? basename($source);
            $path = "documents/demo/{$studentId}/{$application->id}-" . basename($source);

            Storage::disk('public')->put($path, file_get_contents($source));

            Document::create([
                'application_id' => $application->id,
                'scholarship_requirement_id' => $requirement->id,
                'original_filename' => $originalName,
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
