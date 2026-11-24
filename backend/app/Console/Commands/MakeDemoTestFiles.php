<?php

namespace App\Console\Commands;

use App\Models\Application;
use App\Models\ScholarRecord;
use App\Support\DemoListFiles;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/*
| php artisan demo:test-files
|
| Extra FICTIONAL test files for the demonstration, made from the demo students in YOUR database
| (so every Student ID matches), in the column names the importers read:
|
|   approved-large-<program>.pdf     a long approved list over several pages (pagination, bulk processing)
|   approved-large-<program>.xlsx    the same list in Excel
|   approved-with-problems.csv       duplicate row, a row with no Student ID (name only), a wrong ID format,
|                                    a real ID with a different name, an unknown ID, a blank row
|   registrar-enrollment-list.xlsx   the Registrar's enrolled students: every approved applicant and active
|                                    grantee EXCEPT one (to show "not enrolled"), plus other students
|   README.txt                       what to choose and what to expect
|
| Other ready-made files: php artisan demo:approved-lists (one list per program, PDF/Word/Excel/CSV),
| php artisan demo:external-lists, and ai-service/samples/make_test_files.py (scanned TES billing).
| No real student data is used. Output: storage/app/demo-test-files/
*/
class MakeDemoTestFiles extends Command
{
    protected $signature = 'demo:test-files {--dir= : Save somewhere else}';

    protected $description = 'Write extra fictional test files (long multi-page list, list with problems, Registrar list) from the demo students.';

    public function handle(): int
    {
        $dir = $this->option('dir') ?: storage_path('app/demo-test-files');
        File::ensureDirectoryExists($dir);
        File::cleanDirectory($dir);
        $readme = ['FICTIONAL test files for CSU UniScholar (demo students only). Made '.now('Asia/Manila')->format('F j, Y g:i A').'.', ''];

        // 1. A long list for the program with the most forwarded applications, padded with unknown IDs
        $forwarded = Application::with(['student', 'scholarship'])->where('status', 'complete')->get()
            ->filter(fn ($a) => $a->student)->groupBy('scholarship_id')->sortByDesc(fn ($g) => $g->count())->first();
        if ($forwarded) {
            $program = $forwarded->first()->scholarship;
            $slug = trim(preg_replace('/[^a-z0-9]+/', '-', mb_strtolower($program->short_name ?: $program->name)), '-');
            $header = ['No.', 'Student ID', 'Last Name', 'First Name', 'Course', 'Year Level', 'Batch'];
            $rows = [];
            foreach ($forwarded->sortBy(fn ($a) => $a->student->last_name.' '.$a->student->first_name)->values() as $a) {
                $rows[] = ['', $a->student->student_id, mb_strtoupper($a->student->last_name), $a->student->first_name,
                    self::short($a->student->course), $a->student->year_level, 'Batch 3'];
            }
            // other (fictional) grantees of the agency who are not CSU students in this system
            for ($i = 1; count($rows) < 120; $i++) {
                $rows[] = ['', sprintf('2099-%05d', $i), 'SAMPLE'.$i, 'Not In System', 'BS Biology', '1st Year', 'Batch 3'];
            }
            foreach ($rows as $n => &$row) {
                $row[0] = (string) ($n + 1);
            }
            unset($row);
            $titles = ['SAMPLE APPROVED LIST - '.mb_strtoupper($program->name).' (LONG, '.count($rows).' rows)',
                'From: '.($program->provider ?: 'the agency').' - fictional, for testing only', '1st Semester AY '.\App\Models\ExternalGrantee::currentSchoolYear().' - Batch 3'];
            DemoListFiles::pdf("{$dir}/approved-large-{$slug}.pdf", $titles, $header, $rows, [40, 66, 140, 240, 330, 450, 510]);
            DemoListFiles::xlsx("{$dir}/approved-large-{$slug}.xlsx", $titles, $header, $rows);
            $readme[] = "approved-large-{$slug}.pdf / .xlsx -> program: {$program->name}. ".count($rows).' rows over several pages: '
                .$forwarded->count().' forwarded demo students become approved (except any who already hold another scholarship), '
                .(count($rows) - $forwarded->count()).' rows with 2099-xxxxx IDs are "unmatched" (not in the system).';

            // 2. Problems list (CSV) for the same program
            $students = $forwarded->pluck('student')->values();
            $p = [];
            if ($students->count() >= 3) {
                $a = $students[0];
                $b = $students[1];
                $c = $students[2];
                $p[] = [$a->student_id, $a->last_name, $a->first_name, 'Approved'];
                $p[] = [$a->student_id, $a->last_name, $a->first_name, 'Approved'];                  // duplicate row
                $p[] = ['', $b->last_name, $b->first_name, 'Approved'];                                   // no Student ID: matched by name only if unique
                $p[] = [str_replace('-', '', $c->student_id).'X', $c->last_name, $c->first_name, 'Approved']; // wrong ID format
                $p[] = [$c->student_id, 'DIFFERENT', 'Person', 'Approved'];                             // real ID, other name -> check
                $p[] = ['2099-99999', 'UNKNOWN', 'Student', 'Approved'];                                 // unknown ID
                $p[] = ['', '', '', ''];                                                                  // blank row
                DemoListFiles::csv("{$dir}/approved-with-problems.csv", ['Student ID', 'Last Name', 'First Name', 'Approval Status'], $p);
                $readme[] = "approved-with-problems.csv -> program: {$program->name}. Expected: row 2 approved; row 3 a duplicate (error); row 4 matched by name or unmatched;"
                    .' row 5 wrong ID format (unmatched); row 6 "check: name differs"; row 7 unmatched; the blank row is skipped.';
            }
        } else {
            $readme[] = 'No forwarded applications: run "php artisan demo:reset --full" first for the long and problem lists.';
        }

        $test = \App\Models\Student::where('student_id', config('demo.test_student.student_id'))->first();

        // 3. One approved list per open program WITH the test student, so her application can be approved
        //    once it is forwarded (process the file of the program she applied to).
        if ($test) {
            $byProgram = Application::with('student')->where('status', 'complete')->get()->groupBy('scholarship_id');
            foreach (\App\Models\Scholarship::where('status', 'active')->orderBy('name')->get() as $program) {
                $slug = trim(preg_replace('/[^a-z0-9]+/', '-', mb_strtolower($program->short_name ?: $program->name)), '-');
                $people = collect($byProgram->get($program->id, []))->pluck('student')->filter()
                    ->push($test)->unique('id')->sortBy(fn ($s) => $s->last_name.' '.$s->first_name)->values();
                $rows = $people->map(fn ($s, $i) => [(string) ($i + 1), $s->student_id, mb_strtoupper($s->last_name), $s->first_name,
                    self::short($s->course), $s->year_level, 'Approved'])->all();
                $titles = ['SAMPLE APPROVED LIST - '.mb_strtoupper($program->name),
                    'From: '.($program->provider ?: 'the agency').' - fictional, for testing only',
                    \App\Http\Controllers\PayrollController::currentPeriod()];
                $header = ['No.', 'Student ID', 'Last Name', 'First Name', 'Course', 'Year Level', 'Status'];
                $name = "approved-{$slug}-with-".mb_strtolower($test->last_name);
                DemoListFiles::xlsx("{$dir}/{$name}.xlsx", $titles, $header, $rows);
                DemoListFiles::pdf("{$dir}/{$name}.pdf", $titles, $header, $rows, [40, 66, 140, 240, 330, 450, 510]);
            }
            $readme[] = "approved-<program>-with-".mb_strtolower($test->last_name).'.xlsx / .pdf -> one per active program: the students forwarded to that program'
                ." now, plus {$test->first_name} {$test->last_name} ({$test->student_id}). Use the file of the program she applied to, AFTER her application is"
                .' forwarded (Complete); before that her row shows as "not forwarded".';
        }

        // 3b. A numbered TES approved list with EVERY student in the system (A to Z), plus 5 agency rows that are
        //     not CSU students here: tests a longer list in one go (works even right after demo:clean).
        $tes = \App\Models\Scholarship::where('short_name', 'TES')->first()
            ?? \App\Models\Scholarship::where('status', 'active')->orderBy('name')->first();
        $everyone = \App\Models\Student::whereNotNull('student_id')->orderBy('last_name')->orderBy('first_name')->get();
        if ($tes && $everyone->isNotEmpty()) {
            $rows = $everyone->map(fn ($s) => ['', $s->student_id, mb_strtoupper($s->last_name), $s->first_name,
                self::short($s->course), $s->year_level, 'Approved'])->all();
            for ($i = 1; $i <= 5; $i++) {
                $rows[] = ['', sprintf('2099-%05d', 900 + $i), 'NOTINSYSTEM'.$i, 'Sample', 'BS Biology', '1st Year', 'Approved'];
            }
            foreach ($rows as $n => &$row) {
                $row[0] = (string) ($n + 1);
            }
            unset($row);
            $titles = ['SAMPLE APPROVED LIST - '.mb_strtoupper($tes->name).' (NUMBERED, '.count($rows).' students)',
                'From: '.($tes->provider ?: 'the agency').' - fictional, for testing only',
                \App\Http\Controllers\PayrollController::currentPeriod()];
            $header = ['No.', 'Student ID', 'Last Name', 'First Name', 'Course', 'Year Level', 'Status'];
            DemoListFiles::xlsx("{$dir}/approved-tes-numbered-list.xlsx", $titles, $header, $rows);
            DemoListFiles::pdf("{$dir}/approved-tes-numbered-list.pdf", $titles, $header, $rows, [40, 66, 140, 240, 330, 450, 510]);
            $readme[] = 'approved-tes-numbered-list.xlsx / .pdf -> program: '.$tes->name.'. '.count($rows).' numbered rows: all '
                .$everyone->count().' students in the system A to Z, then 5 rows with 2099-xxxxx IDs. Expected: forwarded TES applicants -> approved;'
                .' students with no TES application -> created (approved application made); holders of another active scholarship -> error'
                .' (one-scholarship rule); 2099 rows -> unmatched.';
        }

        // 4. Registrar list: approved applicants + active grantees, minus ONE (shows "not enrolled")
        $ids = Application::with('student')->where('status', 'approved')->get()->pluck('student')
            ->merge(ScholarRecord::with('student')->where('status', 'active')->get()->pluck('student'))
            ->filter()->unique('id')->sortBy(fn ($s) => $s->last_name.' '.$s->first_name)->values();
        // One demo student is left out on purpose ("not enrolled"), preferably one who is approved or a grantee;
        // every other student in the system is enrolled (so it also works right after demo:clean), the test student always.
        $left = $ids->first(fn ($s) => $s->is_demo)
            ?? \App\Models\Student::where('is_demo', true)->orderBy('last_name')->orderBy('first_name')->first();
        $enrolled = \App\Models\Student::whereNotNull('student_id')->get()
            ->reject(fn ($s) => $left && $s->id === $left->id)
            ->when($test, fn ($c) => $c->push($test))->unique('id')
            ->sortBy(fn ($s) => $s->last_name.' '.$s->first_name)->values();
        $rows = $enrolled->map(fn ($s, $i) => [(string) ($i + 1), $s->student_id, mb_strtoupper($s->last_name), $s->first_name, self::short($s->course), $s->year_level])->all();
        for ($i = 1; $i <= 25; $i++) {
            $rows[] = [(string) (count($rows) + 1), sprintf('2098-%05d', $i), 'OTHERSTUDENT'.$i, 'Enrolled', 'BS Information Technology', '2nd Year'];
        }
        $term = \App\Http\Controllers\PayrollController::currentPeriod();
        DemoListFiles::xlsx("{$dir}/registrar-enrollment-list.xlsx",
            ['SAMPLE REGISTRAR LIST OF ENROLLED STUDENTS (fictional)', $term, 'For testing Enrollment Verification only'],
            ['No.', 'Student ID', 'Last Name', 'First Name', 'Course', 'Year Level'], $rows, 'Enrolled');
        $readme[] = "registrar-enrollment-list.xlsx -> Enrollment, term: {$term}. ".$enrolled->count().' students in the system are enrolled'
            .($test ? " (including {$test->first_name} {$test->last_name})" : '').'.'
            .($left ? " {$left->last_name}, {$left->first_name} ({$left->student_id}) is left out on purpose and shows as NOT enrolled." : '');

        File::put("{$dir}/README.txt", implode("\r\n", $readme)."\r\n");
        $this->info("Saved in {$dir}");
        foreach (File::files($dir) as $file) {
            $this->line('  '.$file->getFilename());
        }

        return self::SUCCESS;
    }

    private static function short(?string $course): string
    {
        return str_replace(['Bachelor of Science in ', 'Bachelor of Arts in '], ['BS ', 'AB '], (string) $course);
    }
}
