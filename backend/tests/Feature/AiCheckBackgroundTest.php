<?php

namespace Tests\Feature;

use App\Jobs\ValidateDocumentJob;
use App\Models\Document;
use App\Models\Student;
use App\Models\User;
use App\Support\DocumentChecker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/*
| AI checks must never leave a document on "Checking..." and must not freeze the website:
| with the AI worker running they go to the worker; a second press does not start a second check;
| a check that never finished is released after a few minutes.
*/
class AiCheckBackgroundTest extends TestCase
{
    use RefreshDatabase;

    private function studentWithDocument(string $status = 'uploaded'): array
    {
        Storage::fake('local');
        Storage::disk('local')->put('documents/cor.pdf', '%PDF-1.4 test');
        $user = User::factory()->create(['role' => 'student']);
        $student = Student::create(['user_id' => $user->id, 'student_id' => '2026-77001', 'first_name' => 'Ana',
            'last_name' => 'Reyes', 'course' => 'BSIT', 'year_level' => '1st Year']);
        $document = Document::create(['student_id' => $student->id, 'document_type' => 'Certificate of Registration (COR)',
            'original_filename' => 'cor.pdf', 'file_path' => 'documents/cor.pdf', 'status' => $status]);

        return [$user, $document];
    }

    public function test_with_the_worker_running_the_check_is_queued_and_the_request_returns_at_once(): void
    {
        Queue::fake();
        [$user, $document] = $this->studentWithDocument();
        DocumentChecker::markWorkerAlive();
        Sanctum::actingAs($user);

        $this->postJson("/api/documents/{$document->id}/validate")
            ->assertStatus(202)
            ->assertJsonPath('queued', true);

        $this->assertSame('processing', $document->fresh()->status);
        Queue::assertPushedOn('ai', ValidateDocumentJob::class);
        $this->getJson("/api/documents/{$document->id}/check-status")->assertJsonPath('state', 'running');
    }

    public function test_pressing_again_while_a_check_runs_does_not_start_a_second_check(): void
    {
        Queue::fake();
        [$user, $document] = $this->studentWithDocument('processing');
        Sanctum::actingAs($user);

        $this->postJson("/api/documents/{$document->id}/validate")
            ->assertStatus(202)
            ->assertJsonPath('queued', true);
        Queue::assertNothingPushed();
    }

    public function test_a_check_that_never_finished_is_released(): void
    {
        [$user, $document] = $this->studentWithDocument('processing');
        Document::whereKey($document->id)->update(['updated_at' => now()->subMinutes(DocumentChecker::STALE_MINUTES + 2)]);
        Sanctum::actingAs($user);

        $this->getJson("/api/documents/{$document->id}/check-status")
            ->assertOk()
            ->assertJsonPath('state', 'failed');
        $this->assertSame('uploaded', $document->fresh()->status);
    }

    public function test_the_worker_job_saves_the_result(): void
    {
        [$user, $document] = $this->studentWithDocument('processing');
        Http::fake(['*' => Http::response([
            'success' => true, 'ocr_status' => 'ok', 'is_complete' => true, 'has_name_mismatch' => false,
            'has_missing_information' => false, 'has_wrong_document' => false, 'confidence_score' => 95,
            'extracted_text' => 'ANA REYES 2026-77001', 'extracted_data' => [], 'flags' => [],
        ])]);

        (new ValidateDocumentJob($document->id))->handle();

        $this->assertSame('validated', $document->fresh()->status);
        $this->assertNotNull($document->fresh()->validationResult);
        Sanctum::actingAs($user);
        $this->getJson("/api/documents/{$document->id}/check-status")->assertJsonPath('state', 'done');
    }

    public function test_a_student_cannot_read_the_check_status_of_another_students_document(): void
    {
        [, $document] = $this->studentWithDocument();
        $other = User::factory()->create(['role' => 'student']);
        Student::create(['user_id' => $other->id, 'student_id' => '2026-77002', 'first_name' => 'Ben',
            'last_name' => 'Cruz', 'course' => 'BSIT', 'year_level' => '1st Year']);
        Sanctum::actingAs($other);

        $this->getJson("/api/documents/{$document->id}/check-status")->assertForbidden();
    }
}
