<?php

namespace App\Console\Commands;

use App\Models\Application;
use App\Models\ScholarRecord;
use App\Models\Scholarship;
use App\Models\Student;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class MakeDemoAgencyLists extends Command
{
    protected $signature = 'demo:agency-lists';

    protected $description = 'Write sample agency approved-list CSV files from the current data (for testing Approved Lists).';

    private const HEADER = ['No', 'Student ID', 'Last Name', 'First Name', 'Middle Name', 'Course', 'Year Level', 'Approval Date'];

    public function handle(): int
    {
        $dir = storage_path('app/demo-agency-lists');
        File::ensureDirectoryExists($dir);
        File::cleanDirectory($dir);

        $written = [];
        $date = now('Asia/Manila')->toDateString();

        foreach (Scholarship::where('application_mode', 'oas')->orderBy('id')->get() as $program) {
            $holders = ScholarRecord::where('status', 'active')
                ->where('scholarship_id', '!=', $program->id)
                ->pluck('student_id')->all();

            $students = Application::with('student')
                ->where('scholarship_id', $program->id)
                ->where('status', 'complete')
                ->whereNotIn('student_id', $holders)
                ->orderBy('id')
                ->get()
                ->pluck('student')
                ->filter()
                ->values();

            if ($students->isEmpty()) {
                continue;
            }

            $approved = $students->reject(fn ($s, $i) => $i % 4 === 3)->values();

            $rows = $approved->map(fn ($s) => $this->row($s, $date))->all();
            $rows[] = ['', '2026-99901', 'Sample', 'Notinsystem', '', '', '', $date];

            $written[] = $this->write($dir, $program, $rows, $approved->count().' approved + 1 unknown ID');
        }

        $taken = ScholarRecord::where('status', 'active')->pluck('student_id')->all();
        $free = Student::whereNotIn('id', $taken)
            ->where('student_id', '!=', config('demo.test_student.student_id'))
            ->orderBy('id')
            ->get();

        $offset = 0;
        foreach (Scholarship::where('application_mode', 'agency_direct')->whereIn('short_name', ['DOST', 'Landbank'])->orderBy('id')->get() as $program) {
            $grantees = $free->slice($offset, 3)->values();
            $offset += 3;

            if ($grantees->isEmpty()) {
                continue;
            }

            $rows = $grantees->map(fn ($s) => $this->row($s, $date))->all();
            $written[] = $this->write($dir, $program, $rows, $grantees->count().' grantees (agency-direct)');
        }

        file_put_contents($dir.'/README.txt', implode("\r\n", [
            'SAMPLE approved lists for testing ScholarGuide -> Approved Lists.',
            'Made by "php artisan demo:agency-lists" from the data in the database on '.$date.'.',
            'Generic columns only; this is NOT the official format of CHED or any agency.',
            'In Approved Lists: choose the program, upload the file, choose the "Student ID" column',
            'and the "Approval Date" column, then press Process.',
        ]));

        $this->table(['File', 'Rows'], $written);
        $this->info("Saved in {$dir}");

        return self::SUCCESS;
    }

    private function row(Student $s, string $date): array
    {
        return ['', $s->student_id, $s->last_name, $s->first_name, $s->middle_name, $s->course, $s->year_level, $date];
    }

    private function write(string $dir, Scholarship $program, array $rows, string $note): array
    {
        $name = 'approved-list-'.strtolower(preg_replace('/[^A-Za-z0-9]+/', '-', $program->short_name ?: $program->name)).'.csv';

        $handle = fopen($dir.'/'.$name, 'w');
        fwrite($handle, "\xEF\xBB\xBF");
        fputcsv($handle, self::HEADER);
        foreach (array_values($rows) as $i => $row) {
            $row[0] = $i + 1;
            fputcsv($handle, $row);
        }
        fclose($handle);

        return [$name, $note];
    }
}
