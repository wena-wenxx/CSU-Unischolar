<?php

namespace Tests\Feature;

use App\Models\AgencyListUpload;
use App\Models\AiRecommendation;
use App\Models\Application;
use App\Models\PayrollRecord;
use App\Models\ProcessingBatch;
use App\Models\ScholarRecord;
use App\Models\Scholarship;
use App\Models\ScholarshipNotification;
use App\Models\Setting;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/*
| The whole cycle twice (two batches of the same program), the pending queues after a batch is
| completed, and invitations that name the student, are saved, are not sent twice by accident,
| and can be followed by staff. Every name here is fictional.
*/
class CycleAndInvitationTest extends TestCase
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

    private function student(string $id, string $first, string $last): Student
    {
        $user = User::factory()->create(['role' => 'student']);

        return Student::create(['user_id' => $user->id, 'student_id' => $id, 'first_name' => $first, 'last_name' => $last]);
    }

    private function program(): Scholarship
    {
        return Scholarship::create(['name' => 'Cycle Grant', 'short_name' => 'CG', 'status' => 'active', 'application_mode' => 'oas',
            'application_start' => now()->subDays(5)->toDateString(), 'application_end' => now()->addDays(30)->toDateString(), 'amount' => 5000]);
    }

    private function upload(Scholarship $program, int $batchId, string $file, array $rows, array $extra = [])
    {
        return $this->postJson('/api/staff/agency-lists', array_merge(['scholarship_id' => $program->id, 'processing_batch_id' => $batchId,
            'agency_name' => 'CHED', 'file_name' => $file, 'school_year' => '2026-2027', 'semester' => '1st Semester', 'rows' => $rows], $extra));
    }

    private function tagAll(Scholarship $program): void
    {
        foreach (Application::where('scholarship_id', $program->id)->where('status', 'approved')->where('enrollment_verified', false)->get() as $app) {
            $this->postJson("/api/applications/{$app->id}/verify-enrollment", ['currently_enrolled' => true])->assertOk();
            $this->postJson("/api/applications/{$app->id}/scholar-record")->assertCreated();
        }
    }

    public function test_two_batches_run_one_after_the_other_without_duplicates_or_leftover_work(): void
    {
        $program = $this->program();
        $ana = $this->student('2026-70001', 'Ana', 'Reyes');
        $ben = $this->student('2026-70002', 'Ben', 'Cruz');
        $cara = $this->student('2026-70003', 'Cara', 'Lim');
        $dino = $this->student('2026-70004', 'Dino', 'Sy');
        $studentsBefore = Student::count();

        // ---- Batch 1: list -> approved -> enrollment -> grantee -> payroll
        $b1 = $this->postJson('/api/staff/processing-batches', ['scholarship_id' => $program->id, 'school_year' => '2026-2027',
            'semester' => '1st Semester', 'name' => 'CG Batch 1'])->assertCreated()->json('batch.id');
        $this->upload($program, $b1, 'batch1.pdf', [
            ['row' => 1, 'student_id' => '2026-70001', 'last_name' => 'REYES', 'first_name' => 'ANA'],
            ['row' => 2, 'student_id' => '2026-70002', 'last_name' => 'CRUZ', 'first_name' => 'BEN'],
        ])->assertCreated()->assertJsonPath('upload.created_count', 2);

        $this->getJson('/api/staff/enrollment-lists')->assertOk()->assertJsonPath('waiting', 2);
        $this->tagAll($program);
        $this->getJson('/api/staff/enrollment-lists')->assertOk()->assertJsonPath('waiting', 0);     // left the queue
        $this->assertSame(2, ScholarRecord::where('status', 'active')->count());                     // next stage

        $period = ['period' => '1st Semester AY 2026-2027', 'scholarship_id' => $program->id, 'confirm' => true];
        $this->postJson('/api/payroll/prepare', $period)->assertSuccessful();
        $this->postJson('/api/payroll/prepare', $period)->assertStatus(422);                         // pressing again: nobody to add
        $this->assertSame(2, PayrollRecord::count());                                                // no copies
        $this->postJson("/api/staff/processing-batches/{$b1}/complete")->assertStatus(409);           // payroll not yet released
        foreach (PayrollRecord::all() as $entry) {
            $this->patchJson("/api/payroll/{$entry->id}", ['status' => 'ready'])->assertOk();
            $this->patchJson("/api/payroll/{$entry->id}", ['status' => 'processed'])->assertOk();
        }
        $this->postJson("/api/staff/processing-batches/{$b1}/complete")->assertOk();

        // ---- Batch 2: a returning grantee, a new student, a duplicate row, a conflicting name, an unknown ID
        $b2 = $this->postJson('/api/staff/processing-batches', ['scholarship_id' => $program->id, 'school_year' => '2026-2027',
            'semester' => '2nd Semester', 'name' => 'CG Batch 2'])->assertCreated()->json('batch.id');
        $upload = $this->upload($program, $b2, 'batch2.pdf', [
            ['row' => 1, 'student_id' => '2026-70001', 'last_name' => 'REYES', 'first_name' => 'ANA'],     // already a grantee
            ['row' => 2, 'student_id' => '2026-70003', 'last_name' => 'LIM', 'first_name' => 'CARA'],      // new
            ['row' => 3, 'student_id' => '2026-70003', 'last_name' => 'LIM', 'first_name' => 'CARA'],      // same row twice
            ['row' => 4, 'student_id' => '2026-70004', 'last_name' => 'SANTOS', 'first_name' => 'MARIA'],  // ID of Dino, other name
            ['row' => 5, 'student_id' => '2026-79999', 'last_name' => 'UNKNOWN', 'first_name' => 'PAT'],   // no such student
        ], ['semester' => '2nd Semester'])->assertCreated()->json('upload');

        $details = collect(AgencyListUpload::find($upload['id'])->details)->keyBy('row');
        $this->assertSame('already', $details[1]['result']);
        $this->assertSame('created', $details[2]['result']);
        $this->assertSame('error', $details[3]['result']);
        $this->assertStringContainsString('Duplicate of row 2', $details[3]['note']);
        $this->assertNotContains($details[4]['result'], ['approved', 'created']);   // conflicting name is not approved
        $this->assertSame('unmatched', $details[5]['result']);
        $this->assertSame('not_applied', Application::where('student_id', $dino->id)->value('status') ?? 'not_applied');

        // No student profile was created by the list, and nobody has two applications for one year.
        $this->assertSame($studentsBefore, Student::count());
        $this->assertSame(0, Application::selectRaw('student_id, scholarship_id, school_year, count(*) c')
            ->groupBy('student_id', 'scholarship_id', 'school_year')->havingRaw('count(*) > 1')->get()->count());

        // The same file again needs a confirmation (no double processing).
        $this->upload($program, $b2, 'batch2.pdf', [['row' => 1, 'student_id' => '2026-70003', 'last_name' => 'LIM', 'first_name' => 'CARA']],
            ['semester' => '2nd Semester'])->assertStatus(409);

        // Only the new student waits for enrollment; batch 1 does not come back as new work.
        $this->getJson('/api/staff/enrollment-lists')->assertOk()->assertJsonPath('waiting', 1);
        $this->tagAll($program);
        $this->assertSame(3, ScholarRecord::where('status', 'active')->count());
        $this->assertSame(1, ScholarRecord::where('student_id', $ana->id)->count());                  // no second record

        // Batch 1 grantees (Ana, Ben) were last checked in the 1st semester, so 2nd-semester payroll takes only
        // Cara until the Registrar's list for the 2nd semester is recorded for them.
        $period2 = ['period' => '2nd Semester AY 2026-2027', 'scholarship_id' => $program->id, 'confirm' => true];
        $this->postJson('/api/payroll/prepare', $period2)->assertSuccessful()->assertJsonPath('summary.included', 1);
        $list = $this->postJson('/api/staff/enrollment-lists', ['period' => '2nd Semester AY 2026-2027', 'file_name' => 'reg-2nd.csv',
            'rows' => [['student_id' => '2026-70001', 'last_name' => 'Reyes', 'first_name' => 'Ana'],
                       ['student_id' => '2026-70002', 'last_name' => 'Cruz', 'first_name' => 'Ben']]])->assertSuccessful()->json('list.id');
        $items = ScholarRecord::whereIn('student_id', [$ana->id, $ben->id])->pluck('id')
            ->map(fn ($id) => ['kind' => 'grantee', 'id' => $id, 'enrolled' => true])->all();
        $this->postJson('/api/staff/enrollment/record', ['enrollment_list_id' => $list, 'items' => $items])->assertOk();
        $this->postJson('/api/payroll/prepare', $period2)->assertSuccessful()->assertJsonPath('summary.included', 2);
        $this->postJson('/api/payroll/prepare', $period2)->assertStatus(422);
        $this->assertSame(5, PayrollRecord::count());                                                // 2 + 3, no copies

        // History is kept: batch 1 still has its list, its applications and its payroll.
        $this->assertSame('completed', ProcessingBatch::find($b1)->status);
        $this->assertSame(1, AgencyListUpload::where('processing_batch_id', $b1)->count());
        $this->assertSame(2, Application::where('processing_batch_id', $b1)->count());
        $this->assertSame(2, PayrollRecord::where('period', '1st Semester AY 2026-2027')->count());
    }

    public function test_approved_work_left_in_a_completed_batch_is_not_shown_as_new_pending_work(): void
    {
        $program = $this->program();
        $student = $this->student('2026-70010', 'Ela', 'Go');
        $app = Application::create(['student_id' => $student->id, 'scholarship_id' => $program->id, 'status' => 'approved']);
        $this->getJson('/api/staff/enrollment-lists')->assertJsonPath('waiting', 1);
        $this->getJson('/api/staff/dashboard')->assertJsonPath('enrollment_to_verify', 1);

        // Completing the batch warns about the student still waiting for enrollment verification.
        $this->postJson("/api/staff/processing-batches/{$app->processing_batch_id}/complete")->assertStatus(409)
            ->assertJsonPath('message', fn ($m) => is_string($m));

        $this->postJson("/api/staff/processing-batches/{$app->processing_batch_id}/complete",
            ['confirm_open_items' => true, 'note' => 'Student stopped; agency informed.'])->assertOk();

        $this->getJson('/api/staff/enrollment-lists')->assertJsonPath('waiting', 0);
        $this->getJson('/api/staff/dashboard')->assertJsonPath('enrollment_to_verify', 0);
        $this->assertSame('approved', $app->fresh()->status);                                       // nothing deleted or changed
    }

    // ------------------------------------------------------------------ invitations

    private function recommendation(Student $student, Scholarship $program): AiRecommendation
    {
        return AiRecommendation::create(['student_id' => $student->id, 'scholarship_id' => $program->id, 'score' => 90,
            'met_count' => 3, 'total_count' => 3, 'verdict' => 'eligible', 'checks' => [], 'staff_status' => 'new', 'computed_at' => now()]);
    }

    public function test_an_invitation_names_the_student_is_saved_and_is_not_sent_twice_by_accident(): void
    {
        $program = $this->program();
        $student = $this->student('2026-70020', 'Fe', 'Navarro');
        $rec = $this->recommendation($student, $program);

        $this->postJson("/api/staff/recommendations/{$rec->id}/invite", ['note' => 'Please apply before the deadline.'])
            ->assertOk()->assertJsonPath('message', fn ($m) => str_contains($m, 'Fe Navarro') && !str_contains($m, ' sent to '));

        $notice = ScholarshipNotification::sole();
        $this->assertStringStartsWith('Dear Fe Navarro,', $notice->message);
        $this->assertStringContainsString('Cycle Grant', $notice->message);
        $this->assertStringContainsString('Please apply before the deadline.', $notice->message);

        // A second click is stopped until staff confirm it.
        $this->postJson("/api/staff/recommendations/{$rec->id}/invite")->assertStatus(409)
            ->assertJsonPath('needs_confirmation', true)
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'Fe Navarro was already invited'));
        $this->assertSame(1, ScholarshipNotification::count());
        ScholarshipNotification::query()->update(['read_at' => now(), 'sent_at' => now()->subDays(3)]);
        $this->postJson("/api/staff/recommendations/{$rec->id}/invite", ['confirm_resend' => true])->assertOk();
        $this->assertSame(1, ScholarshipNotification::count());                       // the same record, sent again
        $this->assertNull(ScholarshipNotification::sole()->read_at);                   // unread again for the student
        $this->assertTrue(ScholarshipNotification::sole()->sent_at->isToday());

        // The student sees it under the bell, once per invitation (not again as a plain invitation).
        Sanctum::actingAs($student->user);
        $bell = $this->getJson('/api/student/notifications')->assertOk();
        $this->assertCount(1, $bell->json('opportunities'));
        $this->assertCount(0, $bell->json('invitations'));
        $this->assertStringContainsString('Fe Navarro', $bell->json('opportunities.0.message'));
    }

    public function test_staff_can_follow_each_invitation_from_sent_to_opened_to_applied(): void
    {
        $program = $this->program();
        $student = $this->student('2026-70030', 'Gio', 'Ramos');
        $rec = $this->recommendation($student, $program);
        $this->postJson("/api/staff/recommendations/{$rec->id}/invite")->assertOk();

        $this->getJson('/api/staff/notices')->assertOk()
            ->assertJsonPath('notices.0.student', 'Ramos, Gio')
            ->assertJsonPath('notices.0.student_number', '2026-70030')
            ->assertJsonPath('notices.0.kind', 'Invitation')
            ->assertJsonPath('notices.0.sender', $this->staff->name)
            ->assertJsonPath('notices.0.status', 'sent');

        Sanctum::actingAs($student->user);
        $this->postJson('/api/student/notifications/read')->assertOk();
        Sanctum::actingAs($this->staff);
        $this->getJson('/api/staff/notices')->assertJsonPath('notices.0.status', 'read');

        Application::create(['student_id' => $student->id, 'scholarship_id' => $program->id, 'status' => 'submitted']);
        $this->getJson('/api/staff/notices?status=applied')->assertJsonPath('counts.applied', 1)->assertJsonCount(1, 'notices');
        $this->getJson('/api/staff/notices?q=2026-70030')->assertJsonCount(1, 'notices');
        $this->getJson('/api/staff/notices?q=nobody')->assertJsonCount(0, 'notices');

        // Students cannot read the list.
        Sanctum::actingAs($student->user);
        $this->getJson('/api/staff/notices')->assertStatus(403);
    }

    public function test_a_bulk_notice_without_the_name_tag_still_names_each_student(): void
    {
        $program = $this->program();
        $rec = $this->recommendation($this->student('2026-70040', 'Hana', 'Uy'), $program);
        $this->postJson('/api/staff/recommendations/notifications', ['scholarship_id' => $program->id,
            'recommendation_ids' => [$rec->id], 'message' => 'You may be eligible for this program. Please apply.'])->assertOk();

        $this->assertStringStartsWith('Dear Hana Uy,', ScholarshipNotification::sole()->message);
    }
}
