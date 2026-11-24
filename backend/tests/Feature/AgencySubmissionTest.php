<?php

namespace Tests\Feature;

use App\Models\AgencyListUpload;
use App\Models\Application;
use App\Models\Document;
use App\Models\Scholarship;
use App\Models\ScholarshipRequirement;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/* One sending to an agency = one forwarded date for every student in it; supporting documents
   only of the ticked types; "Done processing" changes no application. */
class AgencySubmissionTest extends TestCase
{
    use RefreshDatabase;

    private function forwarded(int $count): array
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $scholarship = Scholarship::create(['name' => 'Tulong Grant', 'short_name' => 'TG', 'provider' => 'CHED',
            'status' => 'active', 'application_mode' => 'oas']);
        $apps = [];
        for ($i = 1; $i <= $count; $i++) {
            $user = User::factory()->create(['role' => 'student']);
            $student = Student::create(['user_id' => $user->id, 'student_id' => "2026-8000{$i}", 'first_name' => "Ana{$i}",
                'last_name' => 'Cruz', 'course' => 'BSIT', 'year_level' => '1st Year']);
            $apps[] = Application::create(['student_id' => $student->id, 'scholarship_id' => $scholarship->id, 'status' => 'complete',
                'forwarded_at' => now()->subDays($i)]);
        }

        return [$staff, $scholarship, $apps];
    }

    public function test_every_application_in_one_sending_gets_the_same_forwarded_date(): void
    {
        [$staff, , $apps] = $this->forwarded(3);
        Sanctum::actingAs($staff);

        $this->postJson('/api/staff/agency-submissions', [
            'ids' => collect($apps)->pluck('id')->all(),
            'sent_on' => now('Asia/Manila')->toDateString(),
            'sent_by_way' => 'E-mail',
        ])->assertCreated();

        $dates = Application::pluck('forwarded_at')->map->toDateString()->unique();
        $this->assertCount(1, $dates);
        $this->assertSame(1, Application::whereNotNull('agency_submission_id')->distinct()->count('agency_submission_id'));
        $this->assertSame(3, Application::where('status', 'complete')->count());   // status unchanged
    }

    public function test_recording_again_needs_a_confirmation_and_a_future_date_is_refused(): void
    {
        [$staff, , $apps] = $this->forwarded(1);
        Sanctum::actingAs($staff);
        $ids = [$apps[0]->id];

        $this->postJson('/api/staff/agency-submissions', ['ids' => $ids, 'sent_on' => now()->addDays(3)->toDateString()])->assertStatus(422);
        $this->postJson('/api/staff/agency-submissions', ['ids' => $ids, 'sent_on' => now()->toDateString()])->assertCreated();
        $this->postJson('/api/staff/agency-submissions', ['ids' => $ids, 'sent_on' => now()->toDateString()])->assertStatus(409);
        $this->postJson('/api/staff/agency-submissions', ['ids' => $ids, 'sent_on' => now()->toDateString(), 'confirm_resend' => true])->assertCreated();
    }

    public function test_the_documents_zip_has_only_the_ticked_types(): void
    {
        Storage::fake('local');
        [$staff, $scholarship, $apps] = $this->forwarded(1);
        $cor = ScholarshipRequirement::create(['scholarship_id' => $scholarship->id, 'name' => 'Certificate of Registration (COR)', 'is_required' => true]);
        $itr = ScholarshipRequirement::create(['scholarship_id' => $scholarship->id, 'name' => 'Parents\' Income Tax Return', 'is_required' => true]);
        foreach ([[$cor, 'cor.pdf'], [$itr, 'itr.pdf']] as [$req, $file]) {
            Storage::disk('local')->put("documents/{$file}", '%PDF-1.4 '.$file);
            Document::create(['student_id' => $apps[0]->student_id, 'application_id' => $apps[0]->id, 'scholarship_requirement_id' => $req->id,
                'document_type' => $req->name, 'original_filename' => $file, 'file_path' => "documents/{$file}", 'status' => 'validated']);
        }
        Sanctum::actingAs($staff);

        $response = $this->postJson('/api/staff/forwarded/documents-zip', ['ids' => [$apps[0]->id], 'types' => ['Certificate of Registration (COR)']])
            ->assertOk();
        $zip = new \ZipArchive();
        $zip->open($response->baseResponse->getFile()->getPathname());
        $names = collect(range(0, $zip->numFiles - 1))->map(fn ($i) => $zip->getNameIndex($i));
        $this->assertTrue($names->contains(fn ($n) => str_contains($n, 'Certificate of Registration')));
        $this->assertFalse($names->contains(fn ($n) => str_contains($n, 'Income Tax')));
        $this->assertTrue($names->contains('00_contents.csv'));
    }

    public function test_students_cannot_download_supporting_documents(): void
    {
        [, , $apps] = $this->forwarded(1);
        Sanctum::actingAs(User::factory()->create(['role' => 'student']));
        $this->postJson('/api/staff/forwarded/documents-zip', ['ids' => [$apps[0]->id], 'types' => ['COR']])->assertForbidden();
    }

    public function test_done_processing_needs_a_note_when_rows_are_open_and_changes_no_application(): void
    {
        [$staff, $scholarship, $apps] = $this->forwarded(1);
        $upload = AgencyListUpload::create(['scholarship_id' => $scholarship->id, 'agency_name' => 'CHED', 'file_name' => 'list.pdf',
            'uploaded_by' => $staff->id, 'total_rows' => 1, 'unmatched_count' => 1,
            'details' => [['row' => 2, 'student_id' => '2099-00001', 'result' => 'unmatched', 'message' => 'Not found']]]);
        Sanctum::actingAs($staff);

        if ($upload->openRowCount() > 0) {
            $this->postJson("/api/staff/agency-lists/{$upload->id}/done", ['done' => true])->assertStatus(422);
        }
        $this->postJson("/api/staff/agency-lists/{$upload->id}/done", ['done' => true, 'note' => 'Not a CSU student'])->assertOk();
        $this->assertNotNull($upload->fresh()->done_at);
        $this->assertSame('complete', $apps[0]->fresh()->status);
        $this->postJson("/api/staff/agency-lists/{$upload->id}/done", ['done' => false])->assertOk();
        $this->assertNull($upload->fresh()->done_at);
    }

    public function test_the_dashboard_activity_lists_invitations_opened_and_applied(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        Sanctum::actingAs($staff);
        $this->getJson('/api/staff/invitation-activity')->assertOk()->assertJsonStructure(['events', 'totals' => ['invited', 'opened', 'applied']]);
    }
}
