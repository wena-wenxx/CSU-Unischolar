<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\ApplicationStatusLog;
use App\Models\Scholarship;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/* Staff "what to do next" alerts, and resetting one test student to try the process again (fictional names). */
class StaffAlertsAndStudentResetTest extends TestCase
{
    use RefreshDatabase;

    private function submittedBy(User $user, Student $student, string $from = 'draft'): Application
    {
        $program = Scholarship::firstOrCreate(['name' => 'Alert Grant'], ['short_name' => 'AL', 'status' => 'active', 'application_mode' => 'oas']);
        $app = Application::create(['student_id' => $student->id, 'scholarship_id' => $program->id, 'status' => 'submitted', 'submitted_at' => now()]);
        ApplicationStatusLog::record($app, 'submitted', null, $user->id, $from);

        return $app;
    }

    private function student(string $email, bool $demo = true): array
    {
        $user = User::factory()->create(['role' => 'student', 'email' => $email]);
        $student = Student::create(['user_id' => $user->id, 'student_id' => '2026-'.random_int(10000, 99999), 'first_name' => 'Mira',
            'last_name' => 'Santos', 'course' => 'BSIT', 'year_level' => '1st Year', 'is_demo' => $demo]);

        return [$user, $student];
    }

    public function test_a_submitted_application_gives_staff_the_next_step_and_leaves_once_handled(): void
    {
        [$user, $student] = $this->student('student7@carsu.edu.ph');
        $app = $this->submittedBy($user, $student);
        $staff = User::factory()->create(['role' => 'staff']);
        Sanctum::actingAs($staff);

        $alert = $this->getJson('/api/staff/alerts')->assertOk()->json('alerts.0');
        $this->assertStringContainsString('Submitted a AL application', $alert['title']);
        $this->assertStringContainsString('AI check all', $alert['next']);
        $this->assertSame("/staff/applications?review={$app->id}", $alert['link']);
        $this->assertSame(1, $this->getJson('/api/staff/alerts')->json('unseen'));

        $this->postJson('/api/staff/alerts/seen')->assertOk();
        $this->assertSame(0, $this->getJson('/api/staff/alerts')->json('unseen'));

        $app->update(['status' => 'complete']);   // staff forwarded it: no longer a next step
        $this->assertCount(0, $this->getJson('/api/staff/alerts')->json('alerts'));
    }

    public function test_resubmitting_after_needs_action_says_so_and_students_cannot_read_staff_alerts(): void
    {
        [$user, $student] = $this->student('student8@carsu.edu.ph');
        $this->submittedBy($user, $student, 'needs_action');
        Sanctum::actingAs(User::factory()->create(['role' => 'staff']));
        $this->assertStringContainsString('again after fixing', $this->getJson('/api/staff/alerts')->json('alerts.0.title'));

        Sanctum::actingAs($user);
        $this->getJson('/api/staff/alerts')->assertForbidden();
    }

    public function test_the_test_student_can_be_reset_to_apply_again_and_real_students_are_refused(): void
    {
        config(['demo.reset_allowed' => true, 'demo.test_student.email' => 'test.student@carsu.edu.ph']);
        [$user, $student] = $this->student('test.student@carsu.edu.ph', false);
        $this->submittedBy($user, $student);
        [, $real] = $this->student('real.person@carsu.edu.ph', false);
        $this->submittedBy($real->user, $real);

        $this->artisan('demo:reset-student', ['--force' => true])->assertExitCode(0);
        $this->assertSame(0, Application::where('student_id', $student->id)->count());
        $this->assertNotNull(User::find($user->id));   // account kept

        $this->artisan('demo:reset-student', ['who' => 'real.person@carsu.edu.ph', '--force' => true])->assertExitCode(1);
        $this->assertSame(1, Application::where('student_id', $real->id)->count());
    }

    public function test_clean_start_removes_records_but_keeps_accounts_and_refuses_with_real_students(): void
    {
        config(['demo.reset_allowed' => true, 'demo.test_student.email' => 'test.student@carsu.edu.ph']);
        [$user, $student] = $this->student('test.student@carsu.edu.ph', false);
        [$demoUser, $demo] = $this->student('student9@carsu.edu.ph');
        $this->submittedBy($user, $student);
        $this->submittedBy($demoUser, $demo);

        [, $real] = $this->student('real.person@carsu.edu.ph', false);
        $this->artisan('demo:clean', ['--force' => true])->assertExitCode(1);   // a real student is present
        $this->assertSame(2, Application::count());

        $real->user->delete();
        $this->artisan('demo:clean', ['--force' => true])->assertExitCode(0);
        $this->assertSame(0, Application::count());
        $this->assertSame(0, ApplicationStatusLog::count());
        $this->assertNotNull(User::find($user->id));
        $this->assertNotNull(Student::find($demo->id));
        $this->assertSame(1, Scholarship::count());
    }
}
