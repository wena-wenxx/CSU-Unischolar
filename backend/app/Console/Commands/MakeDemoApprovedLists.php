<?php

namespace App\Console\Commands;

use App\Models\Application;
use App\Models\ExternalGrantee;
use App\Models\ScholarRecord;
use App\Support\DemoListFiles;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/*
| php artisan demo:approved-lists   (round 12)
|
| Writes SAMPLE "approved by the agency" lists to test Staff -> Approved
| Lists with each file type the page accepts:
|   approved-<program>.pdf    PDF with real text (a table)
|   approved-<program>.docx   Word table
|   approved-<program>.xlsx   Excel
|   approved-<program>.csv    CSV
| The students are the applications FORWARDED to the agency (status
| "complete") in YOUR database, so the Student IDs always match. Each list
| has the forwarded students who hold no other scholarship (all but one:
| that one stays forwarded), ONE student who already holds another
| scholarship (shows as an error: the one-scholarship rule), and one
| Student ID that is not in ScholarGuide (shows as "unmatched").
| All names are the fictional demo students. The layouts are made up for
| testing, NOT any agency's official form. Run it again after processing a
| list to get new files. Output: storage/app/demo-approved-lists/
*/
class MakeDemoApprovedLists extends Command
{
    protected $signature = 'demo:approved-lists {--dir= : Save somewhere else (the tests use a temporary folder)}';

    protected $description = 'Write sample agency approved lists (pdf, docx, xlsx, csv) from the forwarded applications, for testing Approved Lists.';

    public function handle(): int
    {
        $dir = $this->option('dir') ?: storage_path('app/demo-approved-lists');
        File::ensureDirectoryExists($dir);
        File::cleanDirectory($dir);

        // Students who already hold a scholarship (in ScholarGuide or an outside one from External Grantees).
        $grantees = ScholarRecord::where('status', 'active')->pluck('student_id')
            ->merge(ExternalGrantee::where('status', 'active')->where('match_status', 'matched')->whereNotNull('student_id')->pluck('student_id'))
            ->unique()->flip();

        // Programs with the most forwarded (and free) students first.
        $groups = Application::with(['student', 'scholarship'])
            ->where('status', 'complete')
            ->get()
            ->groupBy('scholarship_id')
            ->filter(fn ($apps) => $apps->reject(fn ($a) => isset($grantees[$a->student_id]))->count() >= 2)
            ->sortByDesc(fn ($apps) => $apps->count())
            ->values();

        if ($groups->isEmpty()) {
            $this->error('No program has 2 or more forwarded applications. Forward some first (Applications -> Complete), or run: php artisan migrate:fresh --seed');

            return self::FAILURE;
        }

        $formats = ['pdf', 'docx', 'xlsx', 'csv'];
        $made = [];
        $header = ['No.', 'Student No.', 'Name of Grantee', 'Course', 'Date Approved'];
        $date = now()->subDays(2)->format('Y-m-d');

        foreach ($formats as $i => $format) {
            $apps = $groups[$i % $groups->count()];
            $program = $apps->first()->scholarship;
            $short = $program->short_name ?: $program->name;

            // Free students: all but one (that one stays forwarded); plus one who already holds
            // another scholarship (the system refuses it), A to Z; plus one unknown ID.
            $free = $apps->reject(fn ($a) => isset($grantees[$a->student_id]))->values();
            $holder = $apps->first(fn ($a) => isset($grantees[$a->student_id]));
            $approved = $free->slice(0, max(1, $free->count() - 1))
                ->when($holder, fn ($list) => $list->push($holder))
                ->sortBy(fn ($a) => $a->student->last_name.' '.$a->student->first_name)->values();
            $rows = [];
            foreach ($approved->values() as $n => $a) {
                $s = $a->student;
                $middle = $s->middle_name && $s->middle_name !== 'Demo' ? ' '.mb_substr($s->middle_name, 0, 1).'.' : '';
                $rows[] = [(string) ($n + 1), $s->student_id, mb_strtoupper($s->last_name).', '.$s->first_name.$middle,
                    mb_strimwidth((string) $s->course, 0, 26, '...'), $date];
            }
            $rows[] = [(string) (count($rows) + 1), '2026-99999', 'SAMPLE, NOT IN SCHOLARGUIDE', 'BS Biology', $date];

            $titles = ['SAMPLE APPROVED LIST - '.mb_strtoupper($program->name),
                'From: '.($program->provider ?: 'the agency').' (fictional sample for testing ScholarGuide)',
                'Approved grantees, '.now()->format('F j, Y')];
            $slug = trim(preg_replace('/[^a-z0-9]+/', '-', mb_strtolower($short)), '-');
            $path = "{$dir}/approved-{$slug}.{$format}";

            match ($format) {
                'pdf' => DemoListFiles::pdf($path, $titles, $header, $rows, [50, 78, 150, 340, 480]),
                'docx' => DemoListFiles::docx($path, $titles, $header, $rows),
                'xlsx' => DemoListFiles::xlsx($path, $titles, $header, $rows),
                'csv' => DemoListFiles::csv($path, $header, $rows),
            };

            $made[] = [basename($path), $program->name, $approved->count() - ($holder ? 1 : 0), $holder ? 1 : 0, 1, $free->count() - $approved->count() + ($holder ? 1 : 0)];
        }

        File::put("{$dir}/README.txt", implode("\r\n", array_merge([
            'SAMPLE agency approved lists for testing Staff -> Approved Lists (fictional students).',
            'Choose the program written below, upload the file, check that "Student No." is picked as the Student ID',
            'column (and "Date Approved" as the date), then press Process.',
            'Expected: the free students become "approved"; one student is an "error" because they already hold another',
            'scholarship (one-scholarship rule); 2026-99999 is "unmatched"; one forwarded student stays forwarded.',
            'PDF, Word and Excel are read by the AI service, so it must be running. CSV works without it.',
            'Run "php artisan demo:approved-lists" again after processing to get lists for the next programs.',
            '',
        ], array_map(fn ($m) => "{$m[0]}  ->  program: {$m[1]}  (expected: {$m[2]} approved, {$m[3]} error, {$m[4]} unmatched)", $made))));

        $this->table(['File', 'Program to choose', 'Will be approved', 'Error (other scholarship)', 'Unmatched', 'Stays forwarded'], $made);
        $this->info("Saved in {$dir}");

        return self::SUCCESS;
    }
}
