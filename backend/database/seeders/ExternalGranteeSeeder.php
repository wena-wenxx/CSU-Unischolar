<?php

namespace Database\Seeders;

use App\Models\Application;
use App\Models\ExternalGrantee;
use App\Models\ExternalGranteeUpload;
use App\Models\ScholarRecord;
use App\Models\Student;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * One SAVED external grantee list, so External Grantees, the Data Bank filter
 * and the "holds an external scholarship" note are not empty at first.
 * Fictional program and people (bulk demo students who hold no scholarship).
 * Upload more lists yourself: php artisan demo:external-lists
 */
class ExternalGranteeSeeder extends Seeder
{
    public const PROGRAM = 'Sample Municipal Scholarship';

    public function run(): void
    {
        if (ExternalGranteeUpload::where('program_name', self::PROGRAM)->exists()) {
            return;
        }

        $staff = User::where('role', 'staff')->first();
        $taken = ScholarRecord::where('status', 'active')->pluck('student_id')
            ->merge(Application::where('status', 'approved')->pluck('student_id'))
            ->unique()->all();

        // From the END of the list, so demo:external-lists (which takes from the start) does not overlap.
        $students = Student::query()
            ->whereNotIn('id', $taken)
            ->where('student_id', 'like', '20__-_____')       // bulk demo students (2024-12345)
            ->where('student_id', 'not like', '20__-000%')    // not the named demo students
            ->orderByDesc('student_id')
            ->limit(3)
            ->get();

        if ($students->count() < 3) {
            return;
        }

        $savedAt = now()->subDays(12);
        $rows = [];
        foreach ($students->values() as $index => $s) {
            $name = mb_strtoupper("{$s->last_name}, {$s->first_name}");
            $rows[] = [
                'row' => $index + 2, 'name' => $name, 'student_id' => $s->student_id,
                'raw' => ($index + 1)." | {$s->student_id} | {$name}", 'note' => '',
                'result' => 'matched', 'method' => 'student_id', 'score' => 100,
                'student' => ['id' => $s->id, 'student_id' => $s->student_id, 'name' => "{$s->first_name} {$s->last_name}", 'score' => 100],
                'suggestions' => [],
            ];
        }
        $rows[] = [
            'row' => 5, 'name' => 'BALAGTAS, FRANCISCO', 'student_id' => '', 'raw' => '4 | | BALAGTAS, FRANCISCO', 'note' => 'No student\'s name is 80% similar.',
            'result' => 'unmatched', 'method' => null, 'score' => null, 'student' => null, 'suggestions' => [],
        ];

        $upload = ExternalGranteeUpload::create([
            'program_name' => self::PROGRAM,
            'agency' => 'Sample Municipal LGU',
            'category' => 'lgu',
            'school_year' => '2026-2027',
            'file_name' => 'municipal-scholars-2026.xlsx',
            'file_path' => null,   // demo record: no original file kept
            'file_type' => 'xlsx',
            'status' => 'saved',
            'read_method' => 'excel',
            'warnings' => [],
            'rows' => $rows,
            'total_rows' => 4,
            'matched_count' => 3,
            'check_count' => 0,
            'unmatched_count' => 1,
            'error_count' => 0,
            'uploaded_by' => $staff?->id,
            'saved_by' => $staff?->id,
            'saved_at' => $savedAt,
        ]);
        $upload->forceFill(['created_at' => $savedAt, 'updated_at' => $savedAt])->saveQuietly();

        foreach ($rows as $line) {
            $grantee = ExternalGrantee::create([
                'upload_id' => $upload->id,
                'student_id' => $line['student']['id'] ?? null,
                'row_number' => $line['row'],
                'extracted_name' => $line['name'],
                'extracted_student_id' => $line['student_id'] ?: null,
                'program_name' => self::PROGRAM,
                'agency' => 'Sample Municipal LGU',
                'category' => 'lgu',
                'school_year' => '2026-2027',
                'match_status' => $line['result'],
                'match_method' => $line['method'],
                'match_score' => $line['score'],
                'status' => 'active',
                'note' => $line['note'] ?: null,
                'confirmed_by' => $staff?->id,
            ]);
            $grantee->forceFill(['created_at' => $savedAt, 'updated_at' => $savedAt])->saveQuietly();
        }
    }
}
