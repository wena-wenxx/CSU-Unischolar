<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/* Student documents are private: not in the public folder, and opened only through a signed link that expires. */
class DocumentPrivacyTest extends TestCase
{
    use RefreshDatabase;

    private function student(): Student
    {
        $user = User::factory()->create(['role' => 'student']);

        return Student::create(['user_id' => $user->id, 'student_id' => '2026-77777', 'first_name' => 'Test', 'last_name' => 'Student']);
    }

    public function test_an_uploaded_document_is_private_and_opens_only_with_a_signed_link(): void
    {
        Storage::fake('local');
        Storage::fake('public');
        $student = $this->student();
        Sanctum::actingAs($student->user);

        $this->post('/api/student/documents', [
            'document_type' => 'Valid ID',
            'file' => UploadedFile::fake()->create('id.pdf', 20, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertSuccessful();

        $document = Document::firstOrFail();
        Storage::disk('local')->assertExists($document->file_path);     // private folder
        Storage::disk('public')->assertMissing($document->file_path);   // not reachable as /storage/...

        $this->get($document->file_url)->assertOk();                                   // the signed link works
        $this->get('/api/files/documents/'.$document->id)->assertForbidden();          // without a signature it does not
        $this->get($document->file_url.'x')->assertForbidden();                        // a changed link does not

        $this->travel(31)->minutes();
        $this->get($document->file_url)->assertOk();                                   // a NEW link is made each time
    }

    public function test_an_expired_link_does_not_open(): void
    {
        Storage::fake('local');
        $student = $this->student();
        Storage::disk('local')->put('documents/a.pdf', '%PDF-1.4 test');
        $document = Document::create(['student_id' => $student->id, 'document_type' => 'Valid ID',
            'original_filename' => 'a.pdf', 'file_path' => 'documents/a.pdf', 'status' => 'uploaded']);

        $link = $document->file_url;
        $this->travel(31)->minutes();
        $this->get($link)->assertForbidden();
    }
}
