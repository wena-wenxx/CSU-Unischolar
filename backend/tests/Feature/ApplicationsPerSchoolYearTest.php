<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\ScholarRecord;
use App\Models\Scholarship;
use App\Models\Setting;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ApplicationsPerSchoolYearTest extends TestCase
{
    use RefreshDatabase;

    private function setUpStudentAndProgram(): array
    {
        $user = User::factory()->create(['role' => 'student']);
        $student = Student::create(['user_id' => $user->id, 'student_id' => '2026-77001', 'first_name' => 'Lea', 'last_name' => 'Umali']);
        $program = Scholarship::create(['name' => 'Yearly Grant', 'status' => 'active', 'application_mode' => 'oas',
            'application_start' => now()->subDays(5)->toDateString(), 'application_end' => now()->addDays(30)->toDateString()]);

        return [$user, $student, $program];
    }

    public function test_a_student_turned_down_last_school_year_can_apply_again_this_year_but_only_once(): void
    {
        [$user, $student, $program] = $this->setUpStudentAndProgram();
        Setting::put('current_school_year', '2026-2027');
        Application::create(['student_id' => $student->id, 'scholarship_id' => $program->id, 'school_year' => '2025-2026', 'status' => 'rejected']);

        Sanctum::actingAs($user);
        $this->postJson("/api/applications", ["scholarship_id" => $program->id])->assertSuccessful()->assertJsonPath('school_year', '2026-2027');
        $this->postJson("/api/applications", ["scholarship_id" => $program->id])->assertStatus(422)
            ->assertJsonPath('message', 'You already applied to this scholarship for school year 2026-2027.');
        $this->assertSame(2, Application::count());
    }

    public function test_an_application_still_in_process_from_last_year_blocks_a_new_one(): void
    {
        [$user, $student, $program] = $this->setUpStudentAndProgram();
        Setting::put('current_school_year', '2026-2027');
        Application::create(['student_id' => $student->id, 'scholarship_id' => $program->id, 'school_year' => '2025-2026', 'status' => 'submitted']);

        Sanctum::actingAs($user);
        $this->postJson('/api/applications', ['scholarship_id' => $program->id])->assertStatus(422)
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'from school year 2025-2026'));
    }

    public function test_a_continuing_grantee_on_a_new_agency_list_is_not_given_a_second_application(): void
    {
        [, $student, $program] = $this->setUpStudentAndProgram();
        $old = Application::create(['student_id' => $student->id, 'scholarship_id' => $program->id, 'school_year' => '2025-2026', 'status' => 'approved']);
        ScholarRecord::create(['student_id' => $student->id, 'scholarship_id' => $program->id, 'status' => 'active', 'currently_enrolled' => true]);
        Sanctum::actingAs(User::factory()->create(['role' => 'staff']));

        $this->postJson('/api/staff/agency-lists', [
            'scholarship_id' => $program->id, 'agency_name' => 'CHED', 'file_name' => 'next-batch.pdf', 'school_year' => '2026-2027',
            'rows' => [['row' => 1, 'student_id' => '2026-77001', 'last_name' => 'UMALI', 'first_name' => 'LEA',
                'award_number' => 'TES-20990101-990000000000-900777', 'total_amount' => 10000]],
        ])->assertCreated()->assertJsonPath('upload.already_count', 1);

        $this->assertSame(1, Application::count());
        $this->assertSame('TES-20990101-990000000000-900777', $old->fresh()->agency_award_number);
        $this->assertSame('TES-20990101-990000000000-900777', ScholarRecord::first()->award_number);
    }
}
