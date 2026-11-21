<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\Student;
use App\Models\User;
use App\Support\DocumentAge;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/*
| An old certificate (an indigency or good-moral certificate issued years ago) is flagged
| for staff. Fictional data only.
*/
class DocumentAgeTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_newest_date_on_the_page_is_the_issue_date(): void
    {
        $date = DocumentAge::newestDate(['9 September 2003', '7th day of July, 2022', '7/7/2022', 'not a date']);
        $this->assertSame('2022-07-07', $date->toDateString());
        $this->assertSame('2022-07-01', DocumentAge::newestDate(['1* day of July 2022'])->toDateString());
    }

    public function test_only_certificates_that_must_be_recent_are_checked(): void
    {
        $this->assertNotNull(DocumentAge::outdatedFlag('Certificate of Indigency', ['July 7, 2022']));
        $this->assertNull(DocumentAge::outdatedFlag('Certificate of Indigency', [now()->subMonth()->format('F j, Y')]));
        $this->assertNull(DocumentAge::outdatedFlag('Birth Certificate', ['September 9, 2003']));
        $this->assertNull(DocumentAge::outdatedFlag('Certificate of Registration (COR)', ['July 7, 2022']));
    }

    public function test_an_old_indigency_certificate_is_flagged_by_the_ai_check(): void
    {
        Storage::fake('local');
        Storage::fake('public');
        $user = User::factory()->create(['role' => 'student', 'email' => 'juan@test.local']);
        Student::create(['user_id' => $user->id, 'student_id' => '2026-00001', 'first_name' => 'Juan', 'last_name' => 'Navarro']);
        Sanctum::actingAs($user);

        $id = $this->post('/api/student/documents', [
            'document_type' => 'Certificate of Indigency',
            'file' => UploadedFile::fake()->create('indigency.pdf', 20, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertSuccessful()->json('document.id');

        Http::fake(['*/validate-document' => Http::response([
            'is_complete' => true, 'has_name_mismatch' => false, 'has_missing_information' => false,
            'has_wrong_document' => false, 'confidence_score' => 100, 'flags' => [], 'ocr_status' => 'ok',
            'extracted_data' => ['dates' => ['6th day of July, 2022']],
        ])]);

        $response = $this->postJson("/api/documents/{$id}/validate")->assertOk();

        $this->assertSame('flagged', $response->json('document.status'));
        $this->assertStringContainsString('dated July 6, 2022', $response->json('document.validation_result.flags'));
        $this->assertSame('flagged', Document::find($id)->status);
    }
}
