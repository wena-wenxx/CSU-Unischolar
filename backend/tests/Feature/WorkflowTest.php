<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\Document;
use App\Models\PayrollRecord;
use App\Models\ScholarRecord;
use App\Models\Scholarship;
use App\Models\ScholarshipRequirement;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/*
| The OAS workflow, step by step, with its guard rails:
|   apply -> submit -> OAS review -> forward (complete) -> agency decision
|   -> approved list -> verify enrollment -> tag grantee -> payroll.
| Every name here is fictional.
*/
class WorkflowTest extends TestCase
{
    use RefreshDatabase;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();
        $this->staff = User::factory()->create(['role' => 'staff']);
    }

    private function student(string $id = '2026-55001'): Student
    {
        $user = User::factory()->create(['role' => 'student']);

        return Student::create(['user_id' => $user->id, 'student_id' => $id, 'first_name' => 'Test', 'last_name' => 'Student'.$id]);
    }

    private function program(string $name = 'Test Program', float $amount = 7500): Scholarship
    {
        $s = Scholarship::create(['name' => $name, 'short_name' => substr($name, 0, 10), 'status' => 'active', 'application_mode' => 'oas',
            'application_start' => now()->subDays(5)->toDateString(), 'application_end' => now()->addDays(30)->toDateString(), 'amount' => $amount]);
        ScholarshipRequirement::create(['scholarship_id' => $s->id, 'name' => 'Valid ID', 'is_required' => true]);

        return $s;
    }

    private function application(Student $student, Scholarship $program, string $status, bool $withDocument = true): Application
    {
        $app = Application::create(['student_id' => $student->id, 'scholarship_id' => $program->id, 'status' => $status,
            'submitted_at' => $status === 'draft' ? null : now()]);
        if ($withDocument) {
            Document::create(['student_id' => $student->id, 'application_id' => $app->id,
                'scholarship_requirement_id' => $program->requirements()->first()->id, 'document_type' => 'Valid ID',
                'original_filename' => 'id.pdf', 'file_path' => 'documents/id.pdf', 'status' => 'validated']);
        }

        return $app;
    }

    private function asStaff(): void
    {
        Sanctum::actingAs($this->staff);
    }

    // ----------------------------------------------------------------- applying

    public function test_a_student_cannot_apply_twice_to_the_same_program(): void
    {
        $student = $this->student();
        $program = $this->program();
        Sanctum::actingAs($student->user);

        $this->postJson('/api/applications', ['scholarship_id' => $program->id])->assertSuccessful();
        $this->postJson('/api/applications', ['scholarship_id' => $program->id])
            ->assertStatus(422)->assertJsonFragment(['message' => 'You already applied to this scholarship.']);
        $this->assertSame(1, Application::count());
    }

    // ------------------------------------------------------------ status rules

    public function test_staff_cannot_skip_steps(): void
    {
        $this->asStaff();
        $draft = $this->application($this->student('2026-55002'), $this->program('P1'), 'draft');
        $submitted = $this->application($this->student('2026-55003'), $this->program('P2'), 'submitted');

        // A draft has not been submitted: OAS cannot act on it yet.
        $this->patchJson("/api/applications/{$draft->id}/review", ['status' => 'under_review'])->assertStatus(422);
        // The agency decides after OAS forwards: submitted -> approved is not allowed.
        $this->patchJson("/api/applications/{$submitted->id}/review", ['status' => 'approved'])->assertStatus(422);
        $this->assertSame('submitted', $submitted->fresh()->status);
        // The allowed next step works.
        $this->patchJson("/api/applications/{$submitted->id}/review", ['status' => 'under_review'])->assertOk();
        $this->assertSame('under_review', $submitted->fresh()->status);
    }

    public function test_an_application_with_a_missing_required_document_cannot_be_forwarded(): void
    {
        $this->asStaff();
        $app = $this->application($this->student(), $this->program(), 'under_review', withDocument: false);

        $this->patchJson("/api/applications/{$app->id}/review", ['status' => 'complete'])->assertStatus(422);
        $this->assertSame('under_review', $app->fresh()->status);
    }

    // ------------------------------------------------- enrollment and tagging

    public function test_a_grantee_is_tagged_only_after_approval_and_enrollment_and_only_once(): void
    {
        $this->asStaff();
        $app = $this->application($this->student(), $this->program(), 'approved');

        $this->postJson("/api/applications/{$app->id}/scholar-record")->assertStatus(422);   // enrollment not verified
        $this->assertSame(0, ScholarRecord::count());

        $this->postJson("/api/applications/{$app->id}/verify-enrollment", ['currently_enrolled' => true])->assertOk();
        $this->postJson("/api/applications/{$app->id}/scholar-record")->assertCreated();
        $this->postJson("/api/applications/{$app->id}/scholar-record")->assertCreated();   // pressing again updates, no copy
        $this->assertSame(1, ScholarRecord::count());
    }

    public function test_a_student_not_enrolled_cannot_be_tagged(): void
    {
        $this->asStaff();
        $app = $this->application($this->student(), $this->program(), 'approved');

        $this->postJson("/api/applications/{$app->id}/verify-enrollment", ['currently_enrolled' => false])->assertOk();
        $this->postJson("/api/applications/{$app->id}/scholar-record")->assertStatus(422);
        $this->assertSame(0, ScholarRecord::count());
    }

    public function test_one_active_scholarship_per_student(): void
    {
        $this->asStaff();
        $student = $this->student();
        $first = $this->application($student, $this->program('First'), 'approved');
        $second = $this->application($student, $this->program('Second'), 'approved');

        foreach ([$first, $second] as $app) {
            $this->postJson("/api/applications/{$app->id}/verify-enrollment", ['currently_enrolled' => true])->assertOk();
        }
        $this->postJson("/api/applications/{$first->id}/scholar-record")->assertCreated();
        $this->postJson("/api/applications/{$second->id}/scholar-record")
            ->assertStatus(422)->assertJsonFragment(['message' => 'Student already has another active scholarship: First.']);
    }

    public function test_re_activating_an_old_grant_follows_the_one_scholarship_rule(): void
    {
        $this->asStaff();
        $student = $this->student();
        ScholarRecord::create(['student_id' => $student->id, 'scholarship_id' => $this->program('Current')->id, 'status' => 'active']);
        $old = ScholarRecord::create(['student_id' => $student->id, 'scholarship_id' => $this->program('Old')->id, 'status' => 'completed']);

        $this->patchJson("/api/scholar-records/{$old->id}", ['status' => 'active'])
            ->assertStatus(422)->assertJsonFragment(['message' => 'Student already has another active scholarship: Current.']);
        $this->assertSame('completed', $old->fresh()->status);
    }

    public function test_the_database_refuses_a_second_record_for_the_same_program(): void
    {
        $student = $this->student();
        $program = $this->program();
        ScholarRecord::create(['student_id' => $student->id, 'scholarship_id' => $program->id, 'status' => 'active']);

        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);
        ScholarRecord::create(['student_id' => $student->id, 'scholarship_id' => $program->id, 'status' => 'active']);
    }

    // ------------------------------------------------------------------ payroll

    public function test_payroll_takes_only_active_enrolled_grantees_and_never_twice_per_period(): void
    {
        $this->asStaff();
        $program = $this->program('Paid Program', 7500);
        $ok = ScholarRecord::create(['student_id' => $this->student('2026-55011')->id, 'scholarship_id' => $program->id,
            'status' => 'active', 'currently_enrolled' => true, 'grantee_tagged_at' => now()]);
        ScholarRecord::create(['student_id' => $this->student('2026-55012')->id, 'scholarship_id' => $program->id,
            'status' => 'active', 'currently_enrolled' => false, 'grantee_tagged_at' => now()]);   // not enrolled
        ScholarRecord::create(['student_id' => $this->student('2026-55013')->id, 'scholarship_id' => $program->id,
            'status' => 'inactive', 'currently_enrolled' => true, 'grantee_tagged_at' => now()]);   // not active

        $period = ['period' => '1st Semester AY 2026-2027', 'scholarship_id' => $program->id];

        $preview = $this->postJson('/api/payroll/prepare', $period)->assertOk()->json('summary');
        $this->assertSame(1, $preview['included']);
        $this->assertEquals(7500, $preview['total_amount']);
        $this->assertSame(0, PayrollRecord::count());   // a preview saves nothing

        $this->postJson('/api/payroll/prepare', $period + ['confirm' => true])->assertSuccessful();
        $this->assertSame(1, PayrollRecord::where('scholar_record_id', $ok->id)->count());

        // Same period again (even written differently): nobody is added twice.
        $this->postJson('/api/payroll/prepare', ['period' => '1st semester  AY 2026-2027', 'scholarship_id' => $program->id, 'confirm' => true])
            ->assertStatus(422);
        $this->assertSame(1, PayrollRecord::count());
    }

    public function test_a_payroll_entry_follows_the_steps_and_a_processed_one_never_changes(): void
    {
        $this->asStaff();
        $record = ScholarRecord::create(['student_id' => $this->student()->id, 'scholarship_id' => $this->program()->id,
            'status' => 'active', 'currently_enrolled' => true]);
        $entry = PayrollRecord::create(['scholar_record_id' => $record->id, 'amount' => 7500, 'period' => '1st Semester AY 2026-2027', 'status' => 'draft']);

        $this->patchJson("/api/payroll/{$entry->id}", ['status' => 'processed'])->assertStatus(422);   // cannot skip Ready
        $this->patchJson("/api/payroll/{$entry->id}", ['amount' => 8000])->assertOk();                 // a draft can be corrected
        $this->patchJson("/api/payroll/{$entry->id}", ['status' => 'ready'])->assertOk();
        $this->patchJson("/api/payroll/{$entry->id}", ['amount' => 9000])->assertStatus(422);         // not after Ready
        $this->patchJson("/api/payroll/{$entry->id}", ['status' => 'processed'])->assertOk();
        $this->patchJson("/api/payroll/{$entry->id}", ['status' => 'draft'])->assertStatus(422);      // processed = final
        $this->patchJson("/api/payroll/{$entry->id}", ['signature' => 'x'])->assertStatus(422);

        $entry->refresh();
        $this->assertSame('processed', $entry->status);
        $this->assertEquals(8000, $entry->amount);
        $this->assertSame(3, \App\Models\ActivityLog::where('action', 'payroll.updated')->count());
    }

    // --------------------------------------------------------- approved lists

    public function test_an_approved_list_changes_only_matching_complete_applications(): void
    {
        $this->asStaff();
        $program = $this->program();
        $forwarded = $this->application($this->student('2026-55021'), $program, 'complete');

        $this->postJson('/api/staff/agency-lists', [
            'scholarship_id' => $program->id, 'agency_name' => 'Sample Agency (fictional)', 'file_name' => 'approved.xlsx',
            'rows' => [
                ['row' => 2, 'student_id' => '202655021'],   // the dash was lost in Excel: still the same student
                ['row' => 3, 'student_id' => '2026-99999'],  // not in the system
                ['row' => 4, 'student_id' => '2026-55021'],  // listed twice
            ],
        ])->assertSuccessful();

        $this->assertSame('approved', $forwarded->fresh()->status);
        $this->assertSame(1, Application::where('status', 'approved')->count());
        $this->assertSame(0, ScholarRecord::count());   // approval never tags a grantee by itself
    }

    // -------------------------------------------------------- AI unavailable

    public function test_when_the_ai_service_is_off_a_document_goes_to_manual_review_not_passed(): void
    {
        Storage::fake('local');
        Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('AI service is off'));
        $student = $this->student();
        Storage::disk('local')->put('documents/id.pdf', '%PDF-1.4 test');
        $document = Document::create(['student_id' => $student->id, 'document_type' => 'Valid ID',
            'original_filename' => 'id.pdf', 'file_path' => 'documents/id.pdf', 'status' => 'uploaded']);

        Sanctum::actingAs($student->user);
        $this->postJson("/api/documents/{$document->id}/validate")
            ->assertStatus(503)
            ->assertJsonFragment(['message' => 'Unable to connect to AI service. The document was sent to manual review.', 'error' => null]);
        $this->assertSame('needs_review', $document->fresh()->status);
    }
}
