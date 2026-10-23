<?php

namespace App\Console\Commands;

use App\Models\Application;
use App\Models\ExternalGrantee;
use App\Models\ScholarRecord;
use App\Models\Student;
use App\Support\DemoListFiles;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/*
| php artisan demo:external-lists
|
| Writes SAMPLE outside-scholarship grantee lists, as if an LGU, a foundation
| or CHED gave the OAS their list, to test Staff -> External Grantees:
|   lgu-educational-assistance.xlsx   Excel, split name columns
|   foundation-scholars.docx          Word table, "LAST, FIRST M." names
|   regional-scholars-list.pdf        PDF with real text (a table)
|   barangay-scholars.csv             CSV
| Each file mixes easy lines (Student ID + name), name-only lines, a name
| with a spelling mistake, a wrong ID, and a person who is not in
| ScholarGuide, so every result (matched / check / unmatched / error) shows.
| The students are taken from the CURRENT database (demo students who hold
| no scholarship), so the files always match your data. All fictional.
| The program names and layouts are made up, NOT any agency's official form.
| Output: storage/app/demo-external-lists/
*/
class MakeDemoExternalLists extends Command
{
    protected $signature = 'demo:external-lists';

    protected $description = 'Write sample outside-scholarship grantee lists (xlsx, docx, pdf, csv) for testing External Grantees.';

    public function handle(): int
    {
        $dir = storage_path('app/demo-external-lists');
        File::ensureDirectoryExists($dir);
        File::cleanDirectory($dir);

        $taken = ScholarRecord::where('status', 'active')->pluck('student_id')
            ->merge(ExternalGrantee::where('status', 'active')->whereNotNull('student_id')->pluck('student_id'))
            ->merge(Application::where('status', 'approved')->pluck('student_id'))
            ->unique()->all();

        // Fictional bulk demo students with no scholarship (never Wena, the real test account).
        $pool = Student::query()
            ->whereNotIn('id', $taken)
            ->where('student_id', 'like', '20__-_____')       // bulk demo students (2024-12345)
            ->where('student_id', 'not like', '20__-000%')    // not the named demo students
            ->orderBy('student_id')
            ->limit(13)
            ->get();

        if ($pool->count() < 13) {
            $this->error('Not enough demo students without a scholarship. Run: php artisan migrate:fresh --seed');

            return self::FAILURE;
        }

        [$a, $b, $c, $d, $e, $f, $g, $h, $i, $j, $k, $l, $m] = $pool->all();
        $mi = fn (Student $s) => $s->middle_name ? mb_substr($s->middle_name, 0, 1).'.' : '';
        $lastFirst = fn (Student $s) => mb_strtoupper("{$s->last_name}, {$s->first_name} ").$mi($s);
        $typo = fn (string $text) => mb_strlen($text) > 4 ? mb_substr($text, 0, 2).mb_substr($text, 3, 1).mb_substr($text, 2, 1).mb_substr($text, 4) : $text.'e';
        $note = 'SAMPLE LIST FOR SCHOLARGUIDE TESTING ONLY - FICTIONAL PEOPLE - NOT AN OFFICIAL DOCUMENT';

        // 1. Excel: No | Student No. | Surname | Given Name | M.I.
        DemoListFiles::xlsx("{$dir}/lgu-educational-assistance.xlsx",
            ['Sample City LGU - Educational Assistance Program', 'List of grantees, School Year 2026-2027', $note],
            ['No.', 'Student No.', 'Surname', 'Given Name', 'M.I.'],
            [
                [1, $a->student_id, $a->last_name, $a->first_name, $mi($a)],
                [2, $b->student_id, $b->last_name, $b->first_name, $mi($b)],
                [3, $c->student_id, $c->last_name, $c->first_name, $mi($c)],
                [4, '', $d->last_name, $d->first_name, $mi($d)],                 // no ID: matched by name
                [5, '', $typo($e->last_name), $e->first_name, $mi($e)],          // spelling mistake
                [6, '', 'Dalisay', 'Cardo', 'P.'],                               // not a CSU student
            ]);

        // 2. Word table: No. | Name of Scholar | ID No.
        DemoListFiles::docx("{$dir}/foundation-scholars.docx",
            ['Sample Foundation Scholarship', 'Scholars enrolled at Caraga State University, 1st Semester 2026-2027', $note],
            ['No.', 'Name of Scholar', 'ID No.'],
            [
                [1, $lastFirst($f), $f->student_id],
                [2, "{$g->first_name} {$g->last_name}", ''],                    // name only
                [3, 'Reyes, Andres B.', $h->student_id],                         // ID of another student -> check
                [4, 'Santos, Liwayway C.', '2023-99999'],                        // unknown ID and name
            ],
            'Prepared by: Sample Foundation Scholarship Office');

        // 3. PDF (real text): No. | Student ID | Name | Course
        DemoListFiles::pdf("{$dir}/regional-scholars-list.pdf",
            ['Sample Regional Scholarship - List of Grantees', 'Caraga State University, School Year 2026-2027'],
            ['No.', 'Student ID', 'Name', 'Course'],
            [
                [1, $i->student_id, $lastFirst($i), self::short($i->course)],
                [2, $j->student_id, $lastFirst($j), self::short($j->course)],
                [3, '', "{$k->first_name} {$mi($k)} {$k->last_name}", self::short($k->course)],
            ],
            [50, 85, 180, 400],
            $note);

        // 4. CSV: Student ID | Full Name
        DemoListFiles::csv("{$dir}/barangay-scholars.csv",
            ['Student ID', 'Full Name'],
            [
                [$l->student_id, "{$l->first_name} {$l->last_name}"],
                [$m->student_id, "{$m->first_name} {$m->last_name}"],
                ['', ''],
            ]);

        File::put("{$dir}/README.txt", implode("\r\n", [
            'SAMPLE OUTSIDE-SCHOLARSHIP LISTS FOR TESTING (fictional people, made-up programs)',
            'Upload them in ScholarGuide: Staff -> External Grantees -> Upload a list.',
            '',
            'File                              Program name to type                 Expected result',
            'lgu-educational-assistance.xlsx   Sample LGU Educational Assistance    5 matched (1 of them by a misspelled name), 1 did not match',
            'foundation-scholars.docx          Sample Foundation Scholarship        2 matched, 1 to check (ID of another student), 1 did not match',
            'regional-scholars-list.pdf        Sample Regional Scholarship          3 matched, 1 error (the "sample list" note at the bottom)',
            'barangay-scholars.csv             Sample Barangay Scholarship          2 matched',
            '',
            'The misspelled line ("'.$typo($e->last_name).', '.$e->first_name.'") should be found by the fuzzy name match',
            '(score about 90%). If a name scores under 80%, it shows as "did not match" with suggestions, and you pick the student.',
            '',
            'To test a SCANNED list (OCR), print regional-scholars-list.pdf and take a clear photo of it,',
            'or take a screenshot, and upload the .jpg / .png. Check every line: OCR can misread letters.',
        ]));

        foreach (File::files($dir) as $file) {
            $this->line('  '.$file->getFilename());
        }
        $this->info("Written to {$dir}");

        return self::SUCCESS;
    }

    private static function short(?string $course): string
    {
        return trim(str_replace(['Bachelor of Science in ', 'Bachelor of '], ['BS ', 'B '], (string) $course));
    }
}
