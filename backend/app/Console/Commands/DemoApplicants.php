<?php

namespace App\Console\Commands;

use App\Models\ActivityLog;
use App\Models\Application;
use App\Models\ApplicationStatusLog;
use App\Models\Document;
use App\Models\Scholarship;
use App\Models\Student;
use Database\Seeders\Support\DemoDocuments as Doc;
use Database\Seeders\Support\DemoPdf;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class DemoApplicants extends Command
{
    protected $signature = 'demo:applicants
        {count=12 : How many demo students apply (1-40)}
        {--program=TES : Short name of the program they apply to (e.g. TES, TDP-TES, TDP-SUC)}';

    protected $description = 'Demo computer only: give 10-15 demo students a submitted application with documents, for staff to process.';

    private const WRONG = [
        Doc::COR => Doc::BARANGAY, Doc::GRADES => Doc::INDIGENCY, Doc::INDIGENCY => Doc::BARANGAY,
        Doc::BIRTH => Doc::ITR, Doc::VALID_ID => Doc::BIRTH, Doc::ITR => Doc::BIRTH,
        Doc::BARANGAY => Doc::INDIGENCY, Doc::GOOD_MORAL => Doc::RECOMMENDATION, Doc::RECOMMENDATION => Doc::GOOD_MORAL,
    ];

    public function handle(): int
    {
        if (!app()->environment(['local', 'testing']) || !config('demo.reset_allowed')) {
            $this->error('Refused: works only with APP_ENV=local and DEMO_RESET_ALLOWED=true (demo computer).');

            return self::FAILURE;
        }

        $count = max(1, min(40, (int) $this->argument('count')));
        $program = Scholarship::with('requirements')
            ->where('short_name', $this->option('program'))
            ->orWhere('name', $this->option('program'))
            ->first();
        if (!$program) {
            $this->error('No program "'.$this->option('program').'". Use its short name, e.g. TES.');

            return self::FAILURE;
        }
        $requirements = $program->requirements->where('is_required', true)->values();
        if ($requirements->isEmpty()) {
            $requirements = $program->requirements->values();
        }

        $students = Student::with('user')
            ->where('is_demo', true)
            ->whereHas('user', fn ($q) => $q->where('role', 'student')->where('is_active', true))
            ->whereDoesntHave('scholarRecords', fn ($q) => $q->where('status', 'active'))
            ->whereDoesntHave('applications', fn ($q) => $q->where('scholarship_id', $program->id))
            ->orderBy('last_name')->orderBy('first_name')
            ->limit($count)->get();

        if ($students->isEmpty()) {
            $this->warn('No demo student is free to apply (all have an active grant or already applied). Run php artisan demo:clean first.');

            return self::FAILURE;
        }

        $made = [];
        DB::transaction(function () use ($students, $program, $requirements, &$made) {
            foreach ($students->values() as $i => $student) {
                $n = $i + 1;
                $problem = match (true) {
                    $n % 7 === 0 => 'blank',
                    $n % 5 === 0 => 'typo',
                    $n % 4 === 0 => 'wrong',
                    default => null,
                };
                $submitted = now()->subMinutes(5 + ($students->count() - $i) * 17);

                $application = Application::create([
                    'student_id' => $student->id,
                    'scholarship_id' => $program->id,
                    'status' => 'submitted',
                    'submitted_at' => $submitted,
                ]);
                $application->forceFill(['created_at' => $submitted->copy()->subHour(), 'updated_at' => $submitted])->save();

                $person = $this->person($student);
                $target = $requirements[$n % $requirements->count()]->name;
                foreach ($requirements as $requirement) {
                    $this->document($application, $student, $requirement, $person, $requirement->name === $target ? $problem : null, $submitted);
                }

                ApplicationStatusLog::record($application, 'submitted', null, $student->user_id, 'draft');
                $made[] = [$student->student_id, "{$student->last_name}, {$student->first_name}", $problem
                    ? ['blank' => "blank scan as {$target}", 'typo' => "surname typo on {$target}", 'wrong' => "wrong document as {$target}"][$problem]
                    : 'all documents correct'];
            }
        });

        $this->table(['Student ID', 'Name', 'What the AI should find'], $made);
        ActivityLog::record(null, 'demo.applicants', count($made)." demo students submitted a {$program->short_name} application (demo data).");
        $this->info(count($made)." demo applications submitted to {$program->name}. Staff: Applications or Auto-Review, then AI check all.");

        return self::SUCCESS;
    }

    private function person(Student $s): array
    {
        $seed = crc32($s->student_id);

        return [
            'first' => $s->first_name,
            'middle' => $s->middle_name ?: 'Dela',
            'last' => $s->last_name,
            'student_id' => $s->student_id,
            'course' => $s->course,
            'year_level' => $s->year_level,
            'gwa' => $s->gwa ?: number_format(1.25 + ($seed % 90) / 100, 2),
            'family_income' => $s->family_income ?: 60000 + ($seed % 180) * 1000,
            'father' => 'Roberto '.($s->middle_name ?: 'Dela').' '.$s->last_name,
            'mother' => 'Maria '.($s->middle_name ?: 'Dela').' '.$s->last_name,
            'birth_date' => 'March '.(1 + $seed % 28).', 2005',
            'barangay' => 'Barangay Ampayon',
        ];
    }

    private function document(Application $application, Student $student, $requirement, array $p, ?string $problem, $at): void
    {
        $kind = $requirement->name;
        if ($problem === 'wrong') {
            $kind = self::WRONG[$kind] ?? Doc::BARANGAY;
        } elseif ($problem === 'typo') {
            $p['last'] = substr($p['last'], 0, -1).(str_ends_with($p['last'], 'a') ? 'o' : 'a');
        }

        $pdf = $problem === 'blank'
            ? DemoPdf::blank()
            : DemoPdf::make(...Doc::content($kind, $p));

        $slug = Doc::FILE_NAMES[$requirement->name] ?? 'document.pdf';
        $path = "documents/demo/applicants/{$student->student_id}/{$application->id}-{$slug}";
        Storage::disk(Document::DISK)->put($path, $pdf);

        $document = Document::create([
            'application_id' => $application->id,
            'student_id' => $student->id,
            'scholarship_requirement_id' => $requirement->id,
            'document_type' => $requirement->name,
            'original_filename' => strtoupper($student->last_name).'_'.$slug,
            'file_path' => $path,
            'status' => 'uploaded',
        ]);
        $document->forceFill(['created_at' => $at->copy()->subMinutes(30), 'updated_at' => $at->copy()->subMinutes(30)])->save();
    }
}
