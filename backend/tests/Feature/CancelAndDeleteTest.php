<?php

namespace Tests\Feature;

use App\Jobs\ValidateDocumentJob;
use App\Models\Application;
use App\Models\Document;
use App\Models\Scholarship;
use App\Models\Student;
use App\Models\User;
use App\Models\ValidationResult;
use App\Support\DocumentChecker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/* "Cancel" on an AI check, and "Delete file" for students (fictional names). */
class CancelAndDeleteTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(string $appStatus = null): array
    {
        Storage::fake('local');
        Storage::disk('local')->put('documents/cor.pdf', '%PDF-1.4 test');
        $user = User::factory()->create(['role' => 'student']);
        $student = Student::create(['user_id' => $user->id, 'student_id' => '2026-'.random_int(10000, 99999), 'first_name' => 'Ana',
            'last_name' => 'Reyes', 'course' => 'BSIT', 'year_level' => '1st Year']);
        $app = null;
        if ($appStatus) {
            $program = Scholarship::firstOrCreate(['name' => 'Merit Grant'], ['short_name' => 'MG', 'status' => 'active', 'application_mode' => 'oas']);
            $app = Application::create(['student_id' => $student->id, 'scholarship_id' => $program->id, 'status' => $appStatus]);
        }
        $document = Document::create(['student_id' => $student->id, 'application_id' => $app?->id, 'document_type' => 'Certificate of Registration (COR)',
            'original_filename' => 'cor.pdf', 'file_path' => 'documents/cor.pdf', 'status' => 'uploaded']);

        return [$user, $document];
    }

    public function test_a_queued_check_can_be_cancelled_and_the_worker_then_skips_it(): void
    {
        Queue::fake();
        [$user, $document] = $this->fixture();
        DocumentChecker::markWorkerAlive();
        Sanctum::actingAs($user);
        $this->postJson("/api/documents/{$document->id}/validate")->assertStatus(202);
        $this->assertSame('processing', $document->fresh()->status);

        $this->postJson("/api/documents/{$document->id}/cancel-check")->assertOk()->assertJsonPath('cancelled', true);
        $this->assertSame('uploaded', $document->fresh()->status);

        Http::fake();   // the worker must not call the AI service at all
        (new ValidateDocumentJob($document->id))->handle();
        Http::assertNothingSent();
        $this->assertNull(ValidationResult::where('document_id', $document->id)->first());

        $this->postJson("/api/documents/{$document->id}/cancel-check")->assertOk()->assertJsonPath('cancelled', false);
    }

    public function test_a_result_that_arrives_after_cancel_is_not_saved(): void
    {
        [$user, $document] = $this->fixture();
        DocumentChecker::begin($document);
        DocumentChecker::cancel($document->fresh());
        Http::fake(['*' => Http::response(['is_complete' => true, 'flags' => [], 'ocr_status' => 'ok'])]);

        $outcome = DocumentChecker::run($document->fresh(), 30);
        $this->assertTrue($outcome['cancelled'] ?? false);
        $this->assertNull(ValidationResult::where('document_id', $document->id)->first());
        $this->assertSame('uploaded', $document->fresh()->status);
    }

    public function test_someone_else_cannot_cancel_a_students_check(): void
    {
        [, $document] = $this->fixture();
        Sanctum::actingAs(User::factory()->create(['role' => 'student']));
        $this->postJson("/api/documents/{$document->id}/cancel-check")->assertForbidden();
    }

    public function test_a_student_can_delete_a_draft_file_but_not_one_of_a_submitted_application(): void
    {
        [$user, $document] = $this->fixture('draft');
        Sanctum::actingAs($user);
        $this->deleteJson("/api/student/documents/{$document->id}")->assertOk();
        $this->assertNull(Document::find($document->id));
        Storage::disk('local')->assertMissing('documents/cor.pdf');

        [$user2, $submitted] = $this->fixture('submitted');
        Sanctum::actingAs($user2);
        $this->deleteJson("/api/student/documents/{$submitted->id}")->assertStatus(422);
        $this->assertNotNull(Document::find($submitted->id));
    }

    public function test_a_student_cannot_delete_another_students_file(): void
    {
        [, $document] = $this->fixture('draft');
        $other = User::factory()->create(['role' => 'student']);
        Student::create(['user_id' => $other->id, 'student_id' => '2026-66002', 'first_name' => 'Ben', 'last_name' => 'Lim', 'course' => 'BSIT', 'year_level' => '1st Year']);
        Sanctum::actingAs($other);
        $this->deleteJson("/api/student/documents/{$document->id}")->assertNotFound();
        $this->assertNotNull(Document::find($document->id));
    }
}
