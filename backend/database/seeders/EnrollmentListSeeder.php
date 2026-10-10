<?php

namespace Database\Seeders;

use App\Http\Controllers\PayrollController;
use App\Models\Application;
use App\Models\EnrollmentList;
use App\Models\EnrollmentListEntry;
use App\Models\ScholarRecord;
use App\Models\Student;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * FICTIONAL DEMO DATA: a Registrar enrollment list for the current term,
 * so "Verify All Enrollments" can be shown. Almost every demo student is on
 * it; on purpose a few are missing (-> Not enrolled) and two have a
 * different surname on the list (-> Needs manual check).
 */
class EnrollmentListSeeder extends Seeder
{
    public function run(): void
    {
        if (EnrollmentList::exists()) {
            return;
        }

        // Students the demo will check: approved and waiting, or active grantees.
        $checked = Application::where('status', 'approved')->where('enrollment_verified', false)->pluck('student_id')
            ->merge(ScholarRecord::where('status', 'active')->pluck('student_id'))
            ->unique()->sort()->values();

        $missing = $checked->filter(fn ($id, $i) => $i % 9 === 4)->take(4)->all();
        $renamed = $checked->filter(fn ($id, $i) => $i % 11 === 7)->reject(fn ($id) => in_array($id, $missing, true))->take(2)->all();

        $list = EnrollmentList::create([
            'period' => PayrollController::currentPeriod(),
            'file_name' => 'registrar-enrolled-students-DEMO.csv',
            'uploaded_by' => User::where('role', 'staff')->value('id'),
            'rows_count' => 0,
        ]);

        $rows = [];
        foreach (Student::orderBy('id')->get() as $student) {
            if (in_array($student->id, $missing, true)) {
                continue;
            }

            $rows[] = [
                'enrollment_list_id' => $list->id,
                'student_number' => EnrollmentList::normalizeId($student->student_id),
                'last_name' => in_array($student->id, $renamed, true) ? 'Santos' : $student->last_name,
                'first_name' => in_array($student->id, $renamed, true) ? 'Maria Clara' : $student->first_name,
                'course' => $student->course,
            ];
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            EnrollmentListEntry::insert($chunk);
        }

        $list->update(['rows_count' => count($rows)]);
        $list->forceFill(['created_at' => now()->subDays(2), 'updated_at' => now()->subDays(2)])->save();
    }
}
