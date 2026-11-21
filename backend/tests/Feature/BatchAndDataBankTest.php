<?php

namespace Tests\Feature;

use App\Models\AgencyListUpload;
use App\Models\Application;
use App\Models\PayrollRecord;
use App\Models\ProcessingBatch;
use App\Models\ScholarRecord;
use App\Models\Scholarship;
use App\Models\Setting;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/*
| Repeatable processing cycles: batches, unmatched rows of approved lists, scholar
| records per school year, enrollment method, Data Bank filters, honest e-mail
| wording and the demo reset. Every name here is fictional.
*/
class BatchAndDataBankTest extends TestCase
{
    use RefreshDatabase;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();
        Setting::put('current_school_year', '2026-2027');
        $this->staff = User::factory()->create(['role' => 'staff']);
        Sanctum::actingAs($this->staff);
    }

    private function student(string $id, string $first = 'Lea', string $last = 'Umali', string $email = null): Student
    {
        $user = User::factory()->create(['role' => 'student'] + ($email ? ['email' => $email] : []));

        return Student::create(['user_id' => $user->id, 'student_id' => $id, 'first_name' => $first, 'last_name' => $last]);
    }

    private function program(string $name = 'Batch Program'): Scholarship
    {
        return Scholarship::create(['name' => $name, 'short_name' => 'BP', 'status' => 'active', 'application_mode' => 'oas',
            'application_start' => now()->subDays(5)->toDateString(), 'application_end' => now()->addDays(30)->toDateString(), 'amount' => 5000]);
    }

    // ------------------------------------------------------------------ batches

    public function test_applications_join_the_open_batch_and_a_completed_batch_stays_intact(): void
    {
        $program = $this->program();
        $first = Application::create(['student_id' => $this->student('2026-81001')->id, 'scholarship_id' => $program->id, 'status' => 'rejected']);
        $batch1 = $first->processingBatch;
        $this->assertNotNull($batch1);
        $this->assertSame('2026-2027', $batch1->school_year);

        // Nothing pending: completing works without confirmation, and nothing is deleted.
        $this->postJson("/api/staff/processing-batches/{$batch1->id}/complete")->assertOk();
        $this->assertSame('completed', $batch1->fresh()->status);
        $this->assertSame(1, Application::count());

        // New work goes into a new batch; the old one keeps its application.
        $second = Application::create(['student_id' => $this->student('2026-81002')->id, 'scholarship_id' => $program->id, 'status' => 'submitted']);
        $this->assertNotSame($batch1->id, $second->processing_batch_id);
        $this->assertSame(1, $batch1->applications()->count());

        $this->getJson("/api/applications?processing_batch_id={$batch1->id}")->assertOk()->assertJsonCount(1)
            ->assertJsonPath('0.id', $first->id);
        $this->getJson("/api/applications?processing_batch_id={$second->processing_batch_id}")->assertOk()->assertJsonCount(1)
            ->assertJsonPath('0.id', $second->id);
    }

    public function test_a_batch_with_open_work_needs_a_confirmation_and_a_reason(): void
    {
        $program = $this->program();
        $app = Application::create(['student_id' => $this->student('2026-81003')->id, 'scholarship_id' => $program->id, 'status' => 'submitted']);
        $id = $app->processing_batch_id;

        $this->postJson("/api/staff/processing-batches/{$id}/complete")->assertStatus(409)->assertJsonPath('needs_confirmation', true);
        $this->postJson("/api/staff/processing-batches/{$id}/complete", ['confirm_open_items' => true])->assertStatus(422);
        $this->postJson("/api/staff/processing-batches/{$id}/complete", ['confirm_open_items' => true, 'note' => 'Agency closed the period.'])->assertOk();
        $this->assertStringContainsString('Agency closed the period.', ProcessingBatch::find($id)->notes);

        $this->postJson("/api/staff/processing-batches/{$id}/reopen")->assertOk();
        $this->assertSame('open', ProcessingBatch::find($id)->status);
    }

    public function test_staff_create_a_batch_and_a_list_goes_into_it(): void
    {
        $program = $this->program();
        $student = $this->student('2026-81004');
        $batch = $this->postJson('/api/staff/processing-batches', ['scholarship_id' => $program->id, 'school_year' => '2026-2027',
            'semester' => '1st Semester'])->assertCreated()->json('batch');
        $this->assertSame('BP - 1st Semester AY 2026-2027', $batch['name']);

        $this->postJson('/api/staff/agency-lists', ['scholarship_id' => $program->id, 'processing_batch_id' => $batch['id'],
            'agency_name' => 'CHED', 'file_name' => 'b1.pdf', 'school_year' => '2026-2027', 'semester' => '1st Semester',
            'rows' => [['row' => 1, 'student_id' => '2026-81004']]])->assertCreated();

        $application = Application::where('student_id', $student->id)->first();
        $this->assertSame($batch['id'], $application->processing_batch_id);
        $this->assertSame('1st Semester', $application->semester);
        $this->assertSame(1, AgencyListUpload::where('processing_batch_id', $batch['id'])->count());
    }

    // ------------------------------------------------------------------ approved lists

    public function test_an_unmatched_row_can_be_linked_to_the_right_student_later(): void
    {
        $program = $this->program();
        $student = $this->student('2026-81005', 'Ana', 'Ramos');
        $upload = $this->postJson('/api/staff/agency-lists', ['scholarship_id' => $program->id, 'agency_name' => 'CHED',
            'file_name' => 'u.pdf', 'school_year' => '2026-2027',
            'rows' => [['row' => 1, 'student_id' => '2026-99999', 'last_name' => 'RAMOS', 'first_name' => 'ANA']]])
            ->assertCreated()->assertJsonPath('upload.unmatched_count', 1)->json('upload');
        $this->assertSame(1, AgencyListUpload::find($upload['id'])->openRowCount());

        $this->postJson("/api/staff/agency-lists/{$upload['id']}/rows/1/link", ['student_id' => $student->id])
            ->assertOk()->assertJsonPath('upload.open_rows', 0)->assertJsonPath('upload.created_count', 1)
            ->assertJsonPath('upload.unmatched_count', 0);

        $row = AgencyListUpload::find($upload['id'])->details[0];
        $this->assertSame('created', $row['result']);
        $this->assertSame('unmatched', $row['resolved_from']);
        $this->assertSame('approved', Application::where('student_id', $student->id)->value('status'));

        // A linked row is not open any more.
        $this->postJson("/api/staff/agency-lists/{$upload['id']}/rows/1/link", ['student_id' => $student->id])->assertStatus(422);
    }

    public function test_linking_a_different_name_needs_a_confirmation_and_a_row_can_be_set_aside(): void
    {
        $program = $this->program();
        $other = $this->student('2026-81006', 'Ben', 'Torres');
        $upload = $this->postJson('/api/staff/agency-lists', ['scholarship_id' => $program->id, 'agency_name' => 'CHED',
            'file_name' => 'v.pdf', 'school_year' => '2026-2027',
            'rows' => [['row' => 1, 'student_id' => '', 'last_name' => 'NOBODY', 'first_name' => 'KNOWN'],
                ['row' => 2, 'student_id' => '2026-99998', 'last_name' => 'GONE', 'first_name' => 'ALSO']]])
            ->assertCreated()->json('upload');

        $this->postJson("/api/staff/agency-lists/{$upload['id']}/rows/1/link", ['student_id' => $other->id])
            ->assertStatus(409)->assertJsonPath('needs_confirmation', true);
        $this->assertSame(0, Application::count());

        $this->postJson("/api/staff/agency-lists/{$upload['id']}/rows/2/set-aside", ['note' => 'Not a CSU student.'])
            ->assertOk()->assertJsonPath('upload.open_rows', 1);
        $this->assertSame('Not a CSU student.', AgencyListUpload::find($upload['id'])->details[1]['resolved_note']);
    }

    public function test_exactly_the_same_file_again_needs_a_confirmation(): void
    {
        $program = $this->program();
        $this->student('2026-81007');
        $body = ['scholarship_id' => $program->id, 'agency_name' => 'CHED', 'school_year' => '2026-2027',
            'file_sha256' => str_repeat('a', 64), 'rows' => [['row' => 1, 'student_id' => '2026-81007']]];

        $this->postJson('/api/staff/agency-lists', $body + ['file_name' => 'first-name.pdf'])->assertCreated();
        $this->postJson('/api/staff/agency-lists', $body + ['file_name' => 'renamed.pdf'])->assertStatus(409)
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'Exactly the same file'));
    }

    public function test_the_result_message_never_says_sent_when_e_mail_is_off(): void
    {
        config(['mail.default' => 'log', 'mail.always_to' => null]);
        $program = $this->program();
        $this->student('2026-81008');

        $message = $this->postJson('/api/staff/agency-lists', ['scholarship_id' => $program->id, 'agency_name' => 'CHED',
            'file_name' => 'm.pdf', 'school_year' => '2026-2027', 'rows' => [['row' => 1, 'student_id' => '2026-81008']]])
            ->assertCreated()->json('message');

        $this->assertStringContainsString('E-mail is off', $message);
        $this->assertStringNotContainsString('e-mail(s) sent', $message);
        $this->getJson('/api/staff/mail-mode')->assertOk()->assertJsonPath('mode', 'off');
    }

    // ------------------------------------------------------------------ scholar records per year

    public function test_a_grantee_gets_one_record_per_school_year_and_the_old_one_is_completed(): void
    {
        $program = $this->program();
        $student = $this->student('2026-81009');
        $old = Application::create(['student_id' => $student->id, 'scholarship_id' => $program->id, 'school_year' => '2025-2026', 'status' => 'approved']);
        $new = Application::create(['student_id' => $student->id, 'scholarship_id' => $program->id, 'school_year' => '2026-2027', 'status' => 'approved']);

        foreach ([$old, $new] as $app) {
            $this->postJson("/api/applications/{$app->id}/verify-enrollment", ['currently_enrolled' => true])->assertOk();
            $this->postJson("/api/applications/{$app->id}/scholar-record")->assertCreated();
        }

        $records = ScholarRecord::orderBy('school_year')->get();
        $this->assertCount(2, $records);
        $this->assertSame(['2025-2026', '2026-2027'], $records->pluck('school_year')->all());
        $this->assertSame(['completed', 'active'], $records->pluck('status')->all());
        $this->assertSame($new->processing_batch_id, $records[1]->processing_batch_id);
        $this->assertSame('manual', $new->fresh()->enrollment_method);

        // Payroll: only the active year, once per period, linked to its batch.
        $this->postJson('/api/payroll/prepare', ['period' => '1st Semester 2026-2027', 'confirm' => true])->assertCreated();
        $this->postJson('/api/payroll/prepare', ['period' => '1st Semester 2026-2027', 'confirm' => true])->assertStatus(422);
        $this->assertSame(1, PayrollRecord::count());
        $this->assertSame($new->processing_batch_id, PayrollRecord::first()->processing_batch_id);
    }

    public function test_enrollment_checked_against_the_registrar_list_records_the_method(): void
    {
        $program = $this->program();
        $student = $this->student('2026-81010');
        $app = Application::create(['student_id' => $student->id, 'scholarship_id' => $program->id, 'status' => 'approved']);
        $list = \App\Models\EnrollmentList::create(['period' => '1st Semester 2026-2027', 'file_name' => 'registrar.xlsx',
            'uploaded_by' => $this->staff->id, 'rows_count' => 1]);

        $this->postJson('/api/staff/enrollment/record', ['enrollment_list_id' => $list->id,
            'items' => [['kind' => 'application', 'id' => $app->id, 'enrolled' => true]]])->assertOk();
        $this->assertSame('registrar_list', $app->fresh()->enrollment_method);
    }

    // ------------------------------------------------------------------ Data Bank

    public function test_rejected_applicants_and_former_scholars_stay_searchable_by_period_and_batch(): void
    {
        $program = $this->program();
        $rejected = $this->student('2026-81011', 'Rita', 'Reyes');
        $former = $this->student('2026-81012', 'Fely', 'Flores');
        $app = Application::create(['student_id' => $rejected->id, 'scholarship_id' => $program->id, 'status' => 'rejected']);
        ScholarRecord::create(['student_id' => $former->id, 'scholarship_id' => $program->id, 'school_year' => '2024-2025', 'status' => 'completed']);

        $this->getJson('/api/staff/data-bank?application_status=rejected')->assertOk()->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.student_id', '2026-81011');
        $this->getJson('/api/staff/data-bank?standing=former')->assertOk()->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.student_id', '2026-81012');
        $this->getJson('/api/staff/data-bank?school_year=2024-2025')->assertOk()->assertJsonPath('total', 1);
        $this->getJson("/api/staff/data-bank?processing_batch_id={$app->processing_batch_id}")->assertOk()->assertJsonPath('total', 1);

        $this->getJson("/api/staff/data-bank/{$rejected->id}")->assertOk()
            ->assertJsonPath('applications.0.processing_batch.id', $app->processing_batch_id);
    }

    // ------------------------------------------------------------------ demo reset

    public function test_demo_reset_is_refused_unless_allowed_and_removes_only_demo_students(): void
    {
        $program = $this->program();
        $demo = $this->student('2026-81013', 'Demo', 'Person', 'student77@carsu.edu.ph');
        $real = $this->student('2026-81014', 'Real', 'Person', 'real.person@carsu.edu.ph');
        Application::create(['student_id' => $demo->id, 'scholarship_id' => $program->id, 'status' => 'submitted']);
        Application::create(['student_id' => $real->id, 'scholarship_id' => $program->id, 'status' => 'submitted']);

        config(['demo.reset_allowed' => false]);
        $this->assertSame(1, Artisan::call('demo:reset', ['--force' => true]));
        $this->assertSame(2, Student::count());

        config(['demo.reset_allowed' => true]);
        $this->assertSame(0, Artisan::call('demo:reset', ['--force' => true]));

        $this->assertNull(Student::find($demo->id));
        $this->assertNotNull(Student::find($real->id));
        $this->assertSame(1, Application::where('student_id', $real->id)->count());
        $this->assertTrue(Scholarship::whereKey($program->id)->exists());
        // The named demo accounts were made again (marked as demo).
        $this->assertTrue(Student::where('is_demo', true)->exists());
        $this->assertFalse(Student::find($real->id)->is_demo);
    }

    public function test_students_cannot_use_batches(): void
    {
        $student = $this->student('2026-81015');
        Sanctum::actingAs($student->user);
        $this->getJson('/api/staff/processing-batches')->assertForbidden();
        $this->getJson('/api/staff/mail-mode')->assertForbidden();
    }
}
