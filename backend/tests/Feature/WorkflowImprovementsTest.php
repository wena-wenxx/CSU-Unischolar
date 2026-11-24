<?php

namespace Tests\Feature;

use App\Models\AiRecommendation;
use App\Models\Application;
use App\Models\Document;
use App\Models\Scholarship;
use App\Models\ScholarshipRequirement;
use App\Models\Student;
use App\Models\User;
use App\Models\ValidationResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/*
| Workflow improvements of Nov 24, 2026 (fictional names): notified students leave the active
| AI Recommendations list; a document verified by staff is not checked by the AI again unless staff
| confirm, and its old AI flag no longer sends the application to manual review; remarks suggested
| from each student's own AI flags; one source for the current school year and semester.
*/
class WorkflowImprovementsTest extends TestCase
{
    use RefreshDatabase;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->staff = User::factory()->create(['role' => 'staff']);
        Sanctum::actingAs($this->staff);
    }

    private function submitted(string $docStatus, array $result = []): array
    {
        $user = User::factory()->create(['role' => 'student']);
        $student = Student::create(['user_id' => $user->id, 'student_id' => '2026-61001', 'first_name' => 'Lara',
            'last_name' => 'Bautista', 'course' => 'BSIT', 'year_level' => '2nd Year']);
        $program = Scholarship::create(['name' => 'Flow Grant', 'short_name' => 'FG', 'status' => 'active', 'application_mode' => 'oas',
            'application_start' => now()->subDay(), 'application_end' => now()->addMonth()]);
        $req = ScholarshipRequirement::create(['scholarship_id' => $program->id, 'name' => 'Certificate of Indigency', 'is_required' => true]);
        $app = Application::create(['student_id' => $student->id, 'scholarship_id' => $program->id, 'status' => 'submitted', 'submitted_at' => now()]);
        Storage::disk('local')->put('documents/ind.pdf', '%PDF-1.4 x');
        $doc = Document::create(['student_id' => $student->id, 'application_id' => $app->id, 'scholarship_requirement_id' => $req->id,
            'document_type' => $req->name, 'original_filename' => 'ind.pdf', 'file_path' => 'documents/ind.pdf', 'status' => $docStatus]);
        if ($result) {
            ValidationResult::create(['document_id' => $doc->id] + $result + ['is_complete' => true, 'has_name_mismatch' => false,
                'has_missing_information' => false, 'has_wrong_document' => false]);
        }

        return [$student, $program, $app, $doc];
    }

    public function test_notified_students_leave_the_active_recommendation_list_and_stay_under_previously_notified(): void
    {
        [$student, $program] = $this->submitted('validated');
        $r = AiRecommendation::create(['student_id' => $student->id, 'scholarship_id' => $program->id, 'score' => 100, 'met_count' => 1,
            'total_count' => 1, 'verdict' => 'eligible', 'checks' => [['label' => 'GWA', 'result' => 'met']], 'staff_status' => 'invited',
            'invited_at' => now(), 'computed_at' => now()]);

        $ids = fn ($view) => collect($this->getJson("/api/staff/recommendations?view={$view}")->assertOk()->json('data'))->pluck('id');
        $this->assertFalse($ids('recommended')->contains($r->id));
        $this->assertTrue($ids('acted')->contains($r->id));
        $this->assertNotNull(AiRecommendation::find($r->id));   // nothing deleted
    }

    public function test_a_document_verified_by_staff_is_not_checked_again_unless_staff_confirm(): void
    {
        [, , , $doc] = $this->submitted('flagged', ['flags' => 'Name not found']);
        $doc->update(['staff_verified_at' => now(), 'staff_verified_by' => $this->staff->id]);
        Http::fake();

        $this->postJson("/api/documents/{$doc->id}/validate")->assertStatus(409)->assertJsonPath('needs_confirmation', true);
        Http::assertNothingSent();
        $this->assertNotNull($doc->fresh()->staff_verified_at);   // the staff decision stays
    }

    public function test_an_old_ai_flag_does_not_override_a_staff_verification_in_auto_review(): void
    {
        [, , $app, $doc] = $this->submitted('flagged', ['flags' => 'Name not found on the document', 'has_name_mismatch' => true]);
        $row = fn () => collect($this->postJson('/api/staff/auto-review', [])->assertOk()->json('rows'))->firstWhere('id', $app->id);

        $this->assertSame('manual', $row()['group']);
        $doc->update(['staff_verified_at' => now(), 'staff_verified_by' => $this->staff->id]);
        $this->assertSame('ready', $row()['group']);
    }

    public function test_suggested_remarks_come_from_the_students_own_flags(): void
    {
        [, , $app] = $this->submitted('flagged', ['flags' => 'Name not found on the document', 'has_name_mismatch' => true]);

        $remark = $this->getJson("/api/staff/applications/{$app->id}/suggested-remark")->assertOk()->json('remarks');
        $this->assertStringContainsString('Dear Lara', $remark);
        $this->assertStringContainsString('Certificate of Indigency', $remark);
        $this->assertStringContainsString('full name', $remark);

        $row = collect($this->postJson('/api/staff/auto-review', [])->json('rows'))->firstWhere('id', $app->id);
        $this->assertSame($remark, $row['suggested_remarks']);
        $this->assertSame('submitted', $app->fresh()->status);   // a suggestion only: nothing sent or changed
    }

    public function test_an_unreadable_file_gets_a_clearer_copy_remark_and_an_unchecked_file_gets_none(): void
    {
        [, , $app, $doc] = $this->submitted('needs_review', ['flags' => 'Very little text could be read']);
        $this->assertStringContainsString('clearer copy', $this->getJson("/api/staff/applications/{$app->id}/suggested-remark")->json('remarks'));

        $doc->update(['status' => 'uploaded']);
        $this->assertNull($this->getJson("/api/staff/applications/{$app->id}/suggested-remark")->json('remarks'));
    }

    public function test_public_settings_give_one_current_school_year_and_semester(): void
    {
        \App\Models\Setting::put('current_school_year', '2026-2027');
        \App\Models\Setting::put('current_semester', '2nd Semester');

        $this->getJson('/api/settings/public')->assertOk()
            ->assertJsonPath('current_school_year', '2026-2027')
            ->assertJsonPath('current_semester', '2nd Semester')
            ->assertJsonPath('semesters', ['1st Semester', '2nd Semester', 'Summer']);
    }
}
