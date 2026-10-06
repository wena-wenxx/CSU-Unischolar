<?php

namespace Database\Seeders;

use Carbon\Carbon;
use Database\Seeders\Support\DemoDocuments as Doc;
use Database\Seeders\Support\DemoPdf;
use Database\Seeders\Support\DemoValidator;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

/**
 * VOLUME demo data: makes the system look like it has run for a full
 * scholarship cycle.
 *
 * ALL OF THIS IS FICTIONAL DEMO DATA. Names are random combinations of
 * common Filipino first names and surnames; they do not belong to real
 * CSU students. Student IDs, contact numbers, incomes and documents are
 * made up. Every document is a watermarked SAMPLE PDF.
 *
 * The AI results stored here were produced by Support/DemoValidator.php,
 * a copy of the rules in ai-service/validation.py, run on each sample
 * document's text. They are demo flags, not accuracy measurements.
 *
 * The same data is produced every time (fixed random seed).
 * Skips itself if bulk data already exists.
 */
class BulkDemoSeeder extends Seeder
{
    private const STUDENTS = 120;
    private const TODAY = '2026-10-05';
    private const CURRENT_PERIOD = '1st Semester AY 2026-2027';

    private const FEMALE = ['Maria', 'Ana', 'Kristine', 'Angelica', 'Jasmine', 'Princess', 'Nicole', 'Camille', 'Rhea', 'Joy',
        'Mae', 'April', 'Lovely', 'Shiela', 'Jenny', 'Grace', 'Mary Joy', 'Kimberly', 'Erika', 'Andrea', 'Trisha', 'Bea',
        'Hannah', 'Ella', 'Sofia', 'Charmaine', 'Rochelle', 'Diane', 'Cristina', 'Lea'];
    private const MALE = ['Juan', 'Jose', 'Mark', 'John Paul', 'Christian', 'Kenneth', 'Jerome', 'Paolo', 'Carlo', 'Miguel',
        'Rafael', 'Daniel', 'Joshua', 'Angelo', 'Bryan', 'Jericho', 'Renz', 'Vincent', 'Neil', 'Ramon', 'Gabriel', 'Adrian',
        'Kyle', 'Jayson', 'Ronald', 'Dominic', 'Lorenzo', 'Patrick', 'Arnel', 'Elmer'];
    private const SURNAMES = ['Abad', 'Agustin', 'Alcantara', 'Amper', 'Aquino', 'Bacus', 'Balili', 'Bautista', 'Cabahug', 'Cagas',
        'Calo', 'Castillo', 'Cruz', 'Dagohoy', 'De Guzman', 'Dela Cruz', 'Del Rosario', 'Domingo', 'Dumdum', 'Ebarle',
        'Espinosa', 'Estrada', 'Flores', 'Galgo', 'Garcia', 'Gonzales', 'Ibañez', 'Jimenez', 'Lagura', 'Lopez',
        'Macaraeg', 'Maglasang', 'Manalo', 'Mendoza', 'Montecillo', 'Muñoz', 'Navarro', 'Ocampo', 'Ortega', 'Pascual',
        'Peñaflor', 'Plaza', 'Quijano', 'Ramos', 'Reyes', 'Rivera', 'Salazar', 'Santiago', 'Santos', 'Soriano',
        'Sumampao', 'Tabanao', 'Tolentino', 'Torres', 'Valdez', 'Villanueva', 'Yap', 'Ybañez', 'Zamora', 'Zapanta'];
    private const BARANGAYS = ['Ampayon', 'Libertad', 'Doongan', 'Bancasi', 'Baan', 'Tiniwisan', 'Taguibo', 'Villa Kananga',
        'Maon', 'Obrero', 'San Vicente', 'Agusan Pequeño', 'Ambago', 'Bonbon', 'Pinamanculan'];

    // course, college, weight
    private const COURSES = [
        ['Bachelor of Science in Information Technology', 'College of Computing and Information Sciences', 22],
        ['Bachelor of Science in Computer Science', 'College of Computing and Information Sciences', 14],
        ['Bachelor of Secondary Education', 'College of Education', 18],
        ['Bachelor of Science in Nursing', 'College of Nursing', 14],
        ['Bachelor of Science in Agriculture', 'College of Agriculture and Agri-Industries', 16],
        ['Bachelor of Science in Civil Engineering', 'College of Engineering and Geo-Sciences', 16],
    ];

    // How popular each program is (higher = more applicants).
    private const POPULARITY = [
        DemoDataSeeder::TES => 30, DemoDataSeeder::CSU_SA => 26, DemoDataSeeder::BUTUAN => 18, DemoDataSeeder::CMSP => 16,
        DemoDataSeeder::DOST => 9, DemoDataSeeder::CHOIR => 4, DemoDataSeeder::DANCE => 4, DemoDataSeeder::KAYAM => 3,
        DemoDataSeeder::ATHLETIC => 5, 'SM College Scholarship' => 8, 'Ayala U-Go Scholarship' => 8, 'BPI Science Scholarship' => 6,
        'Metrobank Scholarship' => 6, 'Aboitiz Future Leaders Scholarship' => 6, 'Megaworld Scholarship' => 7,
        'DMCI Homes Scholarship' => 5, 'San Miguel Foundation Scholarship' => 7, 'Manila Water Foundation Scholarship' => 6,
        'Unilab Foundation Scholarship' => 6,
    ];

    // Status mix for applications that do not end in a grantee record.
    private const STATUS_WEIGHTS = [
        'draft' => 8, 'submitted' => 17, 'under_review' => 16, 'needs_action' => 10,
        'complete' => 12, 'approved' => 9, 'rejected' => 28,
    ];

    private const REJECT_REMARKS = [
        "Not included in the agency's final list of grantees.",
        'General weighted average is below the program requirement.',
        'Household income is above the program ceiling.',
        'Requirements were still incomplete after the deadline.',
        'Slots for this program were already filled.',
    ];

    // When a document is uploaded into the wrong slot, which document is it really?
    private const WRONG_UPLOAD = [
        Doc::INDIGENCY => Doc::BARANGAY, Doc::BARANGAY => Doc::INDIGENCY, Doc::COR => Doc::BARANGAY,
        Doc::GRADES => Doc::INDIGENCY, Doc::VALID_ID => Doc::BIRTH, Doc::BIRTH => Doc::ITR,
        Doc::ITR => Doc::BIRTH, Doc::GOOD_MORAL => Doc::RECOMMENDATION, Doc::RECOMMENDATION => Doc::GOOD_MORAL,
    ];

    private array $programs = [];
    private string $password;
    private int $files = 0;

    public function run(): void
    {
        if (DB::table('students')->where('student_id', 'like', '2024-%')->exists()) {
            $this->command?->info('Bulk demo data already exists - skipped.');
            return;
        }

        mt_srand(20261006); // fixed seed: same data every time

        $this->password = Hash::make('Student@12345');
        $this->loadPrograms();

        DB::transaction(function () {
            for ($i = 0; $i < self::STUDENTS; $i++) {
                $this->seedStudent($this->makePerson($i));
            }
        });

        $this->command?->info("Bulk demo data: {$this->files} sample documents written.");
    }

    // ------------------------------------------------------------------ helpers

    private function chance(float $p): bool
    {
        return mt_rand() / mt_getrandmax() < $p;
    }

    private function pick(array $items)
    {
        return $items[mt_rand(0, count($items) - 1)];
    }

    private function weighted(array $weights)
    {
        $total = array_sum($weights);
        $roll = mt_rand(1, $total);

        foreach ($weights as $key => $weight) {
            $roll -= $weight;
            if ($roll <= 0) {
                return $key;
            }
        }

        return array_key_last($weights);
    }

    private function between(string $from, string $to): Carbon
    {
        $a = Carbon::parse($from)->timestamp;
        $b = max($a, Carbon::parse($to)->timestamp);

        return Carbon::createFromTimestamp(mt_rand($a, $b))->setTime(mt_rand(8, 17), mt_rand(0, 59));
    }

    private function loadPrograms(): void
    {
        foreach (DB::table('scholarships')->get() as $s) {
            $this->programs[$s->name] = [
                'id' => $s->id, 'amount' => (float) $s->amount, 'start' => $s->application_start,
                'end' => $s->application_end, 'status' => $s->status,
                'requirements' => DB::table('scholarship_requirements')->where('scholarship_id', $s->id)
                    ->orderBy('id')->get(['id', 'name'])->all(),
            ];
        }
    }

    // ------------------------------------------------------------------ people

    private function makePerson(int $i): array
    {
        $female = $this->chance(0.55);
        $first = $this->pick($female ? self::FEMALE : self::MALE);
        $last = $this->pick(self::SURNAMES);
        $middle = $this->pick(self::SURNAMES);
        while ($middle === $last) {
            $middle = $this->pick(self::SURNAMES);
        }

        $year = $this->weighted(['2024' => 35, '2025' => 33, '2026' => 32]);
        $courseRow = $this->weighted(array_combine(array_keys(self::COURSES), array_column(self::COURSES, 2)));
        [$course, $college] = self::COURSES[$courseRow];

        return [
            'first' => $first,
            'middle' => $middle,
            'last' => $last,
            // CSU-style ID: entry year + 5 digits (fictional)
            'student_id' => sprintf('%s-%05d', $year, 10000 + $i * 700 + mt_rand(0, 699)),
            'year' => (int) $year,
            'course' => $course,
            'college' => $college,
            'year_level' => ['2026' => '1st Year', '2025' => '2nd Year', '2024' => '3rd Year'][$year],
            'father' => $this->pick(self::MALE) . ' ' . $this->pick(self::SURNAMES) . ' ' . $last,
            'mother' => $this->pick(self::FEMALE) . ' ' . $middle . ' ' . $last,
            'birth_date' => Carbon::create($year - 18 - mt_rand(0, 1), mt_rand(1, 12), mt_rand(1, 28))->format('F j, Y'),
            'barangay' => 'Barangay ' . $this->pick(self::BARANGAYS),
            'gwa' => number_format(mt_rand(125, 225) / 100, 2),
            'family_income' => mt_rand(60, 240) * 1000,
            'contact' => sprintf('09%02d%07d', mt_rand(15, 99), mt_rand(0, 9999999)),
        ];
    }

    private function seedStudent(array $p): void
    {
        $now = Carbon::parse(self::TODAY);
        $joined = Carbon::create($p['year'], mt_rand(6, 8), mt_rand(1, 28));

        $userId = DB::table('users')->insertGetId([
            'name' => "{$p['first']} {$p['last']}",
            'email' => 's' . str_replace('-', '', $p['student_id']) . '@demo.carsu.edu.ph',
            'password' => $this->password,
            'role' => 'student',
            'created_at' => $joined, 'updated_at' => $joined,
        ]);

        $studentRowId = DB::table('students')->insertGetId([
            'user_id' => $userId,
            'student_id' => $p['student_id'],
            'first_name' => $p['first'],
            'middle_name' => $p['middle'],
            'last_name' => $p['last'],
            'course' => $p['course'],
            'year_level' => $p['year_level'],
            'college' => $p['college'],
            'contact_number' => $p['contact'],
            'created_at' => $joined, 'updated_at' => $joined,
        ]);

        $p['row_id'] = $studentRowId;

        // Which programs this student applied to this cycle (3 to 6).
        $open = array_filter(self::POPULARITY, fn ($w, $name) => isset($this->programs[$name])
            && $this->programs[$name]['status'] !== 'inactive', ARRAY_FILTER_USE_BOTH);
        $chosen = [];
        $count = mt_rand(3, 6);
        while (count($chosen) < $count) {
            $chosen[$this->weighted($open)] = true;
        }
        $chosen = array_keys($chosen);

        // About 45% of students currently hold a scholarship (one only).
        $granteeProgram = $this->chance(0.45) ? $this->pick($chosen) : null;

        // About 15% also finished (or dropped) a scholarship last year.
        if ($this->chance(0.15)) {
            $pastOptions = array_values(array_diff(array_keys($open), $chosen));
            $this->seedPastScholarship($p, $this->pick($pastOptions));
        }

        foreach ($chosen as $programName) {
            if ($programName === $granteeProgram) {
                $this->seedCurrentGrantee($p, $programName);
                continue;
            }

            $status = $this->weighted(self::STATUS_WEIGHTS);

            // One active scholarship only: other approvals for a grantee are declined.
            if ($granteeProgram && $status === 'approved') {
                $status = 'rejected';
                $remark = 'Already holds another active scholarship (one active scholarship per student).';
            }

            $this->seedApplication($p, $programName, $status, $remark ?? null);
            unset($remark);
        }
    }

    // ------------------------------------------------------------- applications

    private function seedApplication(array $p, string $programName, string $status, ?string $remark = null, ?Carbon $submitted = null): int
    {
        $program = $this->programs[$programName];
        $today = Carbon::parse(self::TODAY);

        $submitted ??= $status === 'draft'
            ? null
            : $this->between($program['start'], min($program['end'], $today->copy()->subDays(2)->toDateString()));
        $created = ($submitted ?? $this->between($program['start'], self::TODAY))->copy()->subDays(mt_rand(1, 6));

        $verified = false;
        $verifiedAt = null;
        if ($status === 'approved' && $this->chance(0.4)) {
            $verified = true;
            $verifiedAt = $submitted->copy()->addDays(mt_rand(15, 40))->min($today);
        }

        $applicationId = DB::table('applications')->insertGetId([
            'student_id' => $p['row_id'],
            'scholarship_id' => $program['id'],
            'status' => $status,
            'remarks' => $remark ?? ($status === 'rejected' ? $this->pick(self::REJECT_REMARKS) : null),
            'submitted_at' => $submitted,
            'enrollment_verified' => $verified,
            'enrollment_verified_at' => $verifiedAt,
            'created_at' => $created,
            'updated_at' => $verifiedAt ?? $submitted ?? $created,
        ]);

        $this->seedDocuments($p, $applicationId, $program, $status, $submitted ?? $created);

        return $applicationId;
    }

    private function seedCurrentGrantee(array $p, string $programName): void
    {
        $program = $this->programs[$programName];

        // 40% are continuing scholars first tagged last school year.
        $continuing = $this->chance(0.4);
        $submitted = $continuing
            ? $this->between('2025-07-01', '2025-08-20')
            : $this->between($program['start'], min($program['end'], '2026-09-10'));
        $verifiedAt = $submitted->copy()->addDays(mt_rand(15, 30));
        $taggedAt = $verifiedAt->copy()->addDays(mt_rand(1, 5));

        $applicationId = $this->seedApplication($p, $programName, 'approved', null, $submitted);
        DB::table('applications')->where('id', $applicationId)->update([
            'enrollment_verified' => true, 'enrollment_verified_at' => $verifiedAt, 'updated_at' => $verifiedAt,
        ]);

        // A few stopped this semester (leave of absence, shifted, ...).
        $status = $this->chance(0.08) ? 'inactive' : 'active';
        $hasAtm = $this->chance(0.7);

        $recordId = DB::table('scholar_records')->insertGetId([
            'student_id' => $p['row_id'],
            'scholarship_id' => $program['id'],
            'status' => $status,
            'currently_enrolled' => $status === 'active',
            'has_atm' => $hasAtm,
            'grantee_tagged_at' => $taggedAt,
            'remarks' => $status === 'inactive' ? 'On leave of absence this semester.' : null,
            'created_at' => $taggedAt, 'updated_at' => $taggedAt,
        ]);

        $periods = $continuing ? ['1st Semester AY 2025-2026', '2nd Semester AY 2025-2026'] : [];
        foreach ($periods as $k => $period) {
            $this->payroll($recordId, $program['amount'], $period, 'processed', $hasAtm,
                Carbon::parse($k === 0 ? '2025-11-20' : '2026-04-20'));
        }

        if ($status === 'active') {
            // Current semester: some ready, some draft, some not prepared yet (for batch payroll).
            $current = $this->weighted(['ready' => 35, 'draft' => 35, 'none' => 30]);
            if ($current !== 'none') {
                $this->payroll($recordId, $program['amount'], self::CURRENT_PERIOD, $current, $hasAtm,
                    Carbon::parse('2026-09-25')->addDays(mt_rand(0, 9)));
            }
        }
    }

    private function seedPastScholarship(array $p, string $programName): void
    {
        $program = $this->programs[$programName];
        $submitted = $this->between('2025-06-15', '2025-08-15');
        $verifiedAt = $submitted->copy()->addDays(mt_rand(15, 30));
        $taggedAt = $verifiedAt->copy()->addDays(mt_rand(1, 5));
        $completed = $this->chance(0.8);
        $hasAtm = $this->chance(0.7);

        $applicationId = $this->seedApplication($p, $programName, 'approved', null, $submitted);
        DB::table('applications')->where('id', $applicationId)->update([
            'enrollment_verified' => true, 'enrollment_verified_at' => $verifiedAt, 'updated_at' => $verifiedAt,
        ]);

        $recordId = DB::table('scholar_records')->insertGetId([
            'student_id' => $p['row_id'],
            'scholarship_id' => $program['id'],
            'status' => $completed ? 'completed' : 'inactive',
            'currently_enrolled' => false,
            'has_atm' => $hasAtm,
            'grantee_tagged_at' => $taggedAt,
            'remarks' => $completed ? 'Completed AY 2025-2026.' : 'Did not meet the retention grade in AY 2025-2026.',
            'created_at' => $taggedAt, 'updated_at' => Carbon::parse('2026-06-15'),
        ]);

        $this->payroll($recordId, $program['amount'], '1st Semester AY 2025-2026', 'processed', $hasAtm, Carbon::parse('2025-11-20'));
        if ($completed) {
            $this->payroll($recordId, $program['amount'], '2nd Semester AY 2025-2026', 'processed', $hasAtm, Carbon::parse('2026-04-20'));
        }
    }

    private function payroll(int $recordId, float $amount, string $period, string $status, bool $hasAtm, Carbon $at): void
    {
        DB::table('payroll_records')->insert([
            'scholar_record_id' => $recordId,
            'amount' => $amount, // demo amount
            'period' => $period,
            'bank_atm_status' => $hasAtm ? 'Yes' : 'No',
            'status' => $status,
            'signature' => $status === 'processed' ? 'Signed' : null,
            'created_at' => $at, 'updated_at' => $at,
        ]);
    }

    // ---------------------------------------------------------------- documents

    private function seedDocuments(array $p, int $applicationId, array $program, string $status, Carbon $uploadedAt): void
    {
        $requirements = $program['requirements'];

        // Drafts have only some documents so far.
        if ($status === 'draft') {
            $requirements = array_slice($requirements, 0, mt_rand(0, max(0, count($requirements) - 1)));
        }

        // Did staff already run the AI check on this application?
        $checked = in_array($status, ['under_review', 'needs_action', 'complete', 'approved', 'rejected'], true)
            || ($status === 'submitted' && $this->chance(0.3));

        // needs_action: exactly one document has a clear problem (OAS asked to fix it).
        $mustFlag = $status === 'needs_action' && $requirements ? mt_rand(0, count($requirements) - 1) : -1;
        $problemRate = ['rejected' => 0.10, 'approved' => 0.01][$status] ?? 0.03;

        $remark = null;

        foreach ($requirements as $index => $requirement) {
            $problem = null;
            if ($index === $mustFlag) {
                $problem = $this->weighted(['wrong' => 50, 'missing_id' => 25, 'unreadable' => 25]);
                if ($problem === 'missing_id' && !in_array($requirement->name, [Doc::COR, Doc::GRADES, Doc::VALID_ID], true)) {
                    $problem = 'wrong';
                }
            } elseif ($checked && $this->chance($problemRate)) {
                $problem = $this->weighted(['typo' => 35, 'wrong' => 30, 'missing_id' => 15, 'unreadable' => 10, 'ai_down' => 10]);
                if ($problem === 'missing_id' && !in_array($requirement->name, [Doc::COR, Doc::GRADES, Doc::VALID_ID], true)) {
                    $problem = 'typo';
                }
            }

            [$pdf, $text] = $this->samplePdf($requirement->name, $p, $problem);

            $slug = Doc::FILE_NAMES[$requirement->name] ?? 'document.pdf';
            $path = "documents/demo/bulk/{$p['student_id']}/{$applicationId}-{$slug}";
            Storage::disk('public')->put($path, $pdf);
            $this->files++;

            $original = $this->chance(0.5)
                ? strtoupper(preg_replace('/[^A-Za-z]/', '', iconv('UTF-8', 'ASCII//TRANSLIT', $p['last']))) . '_' . $slug
                : $slug;

            $at = $uploadedAt->copy()->subMinutes(mt_rand(5, 600));
            $docStatus = 'uploaded';

            $documentId = DB::table('documents')->insertGetId([
                'application_id' => $applicationId,
                'scholarship_requirement_id' => $requirement->id,
                'original_filename' => $original,
                'file_path' => $path,
                'document_type' => null,
                'status' => $docStatus,
                'created_at' => $at, 'updated_at' => $at,
            ]);

            if (!$checked) {
                continue;
            }

            $checkedAt = $uploadedAt->copy()->addDays(mt_rand(1, 6))->min(Carbon::parse(self::TODAY));

            if ($problem === 'ai_down') {
                $result = [
                    'is_complete' => false, 'has_name_mismatch' => false, 'has_missing_information' => false,
                    'has_wrong_document' => false, 'confidence_score' => null, 'flags' => ['AI service unavailable at the time of checking - needs manual review.'],
                    'extracted_data' => null,
                ];
                $text = null;
                $docStatus = 'needs_review';
            } else {
                $result = DemoValidator::validate($text, "{$p['first']} {$p['last']}", $p['student_id'], $requirement->name, $p['last']);
                $docStatus = $result['flags'] ? 'flagged' : 'validated';
            }

            DB::table('validation_results')->insert([
                'document_id' => $documentId,
                'is_complete' => $result['is_complete'],
                'has_name_mismatch' => $result['has_name_mismatch'],
                'has_missing_information' => $result['has_missing_information'],
                'has_wrong_document' => $result['has_wrong_document'],
                'confidence_score' => $result['confidence_score'],
                'extracted_text' => $text,
                'extracted_data' => $result['extracted_data'] ? json_encode($result['extracted_data'], JSON_UNESCAPED_UNICODE) : null,
                'flags' => $result['flags'] ? implode("\n", $result['flags']) : null,
                'created_at' => $checkedAt, 'updated_at' => $checkedAt,
            ]);

            DB::table('documents')->where('id', $documentId)->update(['status' => $docStatus, 'updated_at' => $checkedAt]);

            if ($index === $mustFlag) {
                $remark = $this->fixRequest($requirement->name, $problem);
            }
        }

        if ($remark) {
            DB::table('applications')->where('id', $applicationId)->update(['remarks' => $remark]);
        }
    }

    /** @return array [pdf bytes, text the AI would read] */
    private function samplePdf(string $requirement, array $p, ?string $problem): array
    {
        if ($problem === 'unreadable') {
            return [DemoPdf::blank(), ''];
        }

        $kind = $requirement;
        $person = $p;

        if ($problem === 'wrong') {
            $kind = self::WRONG_UPLOAD[$requirement] ?? Doc::BARANGAY;
        } elseif ($problem === 'missing_id') {
            $person['student_id'] = '';
        } elseif ($problem === 'typo') {
            $person['last'] = $this->typo($p['last']);
            $person['father'] = str_replace($p['last'], $person['last'], $p['father']);
        }

        [$title, $lines, $issuer] = Doc::content($kind, $person);

        return [DemoPdf::make($title, $lines, $issuer), Doc::text($title, $lines, $issuer)];
    }

    // Change one letter, like a typing mistake on the document.
    private function typo(string $name): string
    {
        $letters = preg_split('//u', $name, -1, PREG_SPLIT_NO_EMPTY);
        $positions = array_keys(array_filter($letters, fn ($c) => preg_match('/[a-z]/', $c)));
        $i = $positions[intdiv(count($positions), 2)] ?? 0;
        $letters[$i] = $letters[$i] === 'a' ? 'o' : ($letters[$i] === 'o' ? 'a' : ($letters[$i] === 'e' ? 'i' : 'a'));

        return implode('', $letters);
    }

    private function fixRequest(string $requirement, ?string $problem): string
    {
        return match ($problem) {
            'wrong' => "The file uploaded for {$requirement} is a different document. Please upload your {$requirement}.",
            'missing_id' => "Your Student ID number is not visible on your {$requirement}. Please upload a complete copy.",
            'unreadable' => "Your {$requirement} is blank or too faint to read. Please upload a clearer copy.",
            default => "Please re-upload your {$requirement}.",
        };
    }
}
