<?php

namespace Tests\Feature;

use App\Models\AgencyListUpload;
use App\Models\AiRecommendation;
use App\Models\Application;
use App\Models\PayrollRecord;
use App\Models\ScholarRecord;
use App\Models\Scholarship;
use App\Models\ScholarshipNotification;
use App\Models\Setting;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/*
| The fixes made after the Nov 23, 2026 audit: no invitation for a closed program, tagging twice
| keeps the record and its ATM details, payroll asks for each term's enrollment check, the billing
| statement export, the earlier-list note, a plain 401 for signed-out API calls, and blank scans going
| to manual review. Every name here is fictional.
*/
class AuditFixesTest extends TestCase
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

    private function student(string $id, string $first, string $last, ?string $middle = null): Student
    {
        $user = User::factory()->create(['role' => 'student']);

        return Student::create(['user_id' => $user->id, 'student_id' => $id, 'first_name' => $first,
            'middle_name' => $middle, 'last_name' => $last, 'sex' => 'Female', 'course' => 'BS Agriculture', 'year_level' => '2nd Year']);
    }

    private function program(array $extra = []): Scholarship
    {
        return Scholarship::create($extra + ['name' => 'Audit Grant', 'short_name' => 'AG', 'status' => 'active', 'application_mode' => 'oas',
            'application_start' => now()->subDays(5)->toDateString(), 'application_end' => now()->addDays(30)->toDateString(), 'amount' => 5000]);
    }

    /** List -> approved -> enrollment -> grantee, for the first semester. */
    private function grantee(Scholarship $program, Student $student, string $file = 'list1.pdf', string $award = 'TES-1'): Application
    {
        $this->postJson('/api/staff/agency-lists', ['scholarship_id' => $program->id, 'agency_name' => 'CHED', 'file_name' => $file,
            'school_year' => '2026-2027', 'semester' => '1st Semester',
            'rows' => [['row' => 1, 'student_id' => $student->student_id, 'last_name' => $student->last_name,
                'first_name' => $student->first_name, 'award_number' => $award, 'amount' => '10,000.00']]])->assertCreated();
        $app = Application::where('student_id', $student->id)->where('scholarship_id', $program->id)->sole();
        $this->postJson("/api/applications/{$app->id}/verify-enrollment", ['currently_enrolled' => true])->assertOk();
        $this->postJson("/api/applications/{$app->id}/scholar-record", ['has_atm' => true, 'atm_funds' => 'yes'])->assertCreated();

        return $app;
    }

    public function test_no_invitation_is_saved_for_a_closed_program_and_the_name_has_the_middle_initial(): void
    {
        $open = $this->program();
        $closed = $this->program(['name' => 'Closed Grant', 'short_name' => 'CL', 'application_end' => now()->subDay()->toDateString()]);
        $fe = $this->student('2026-71001', 'Fe', 'Navarro', 'Lopez');
        $make = fn ($p) => AiRecommendation::create(['student_id' => $fe->id, 'scholarship_id' => $p->id, 'score' => 90, 'met_count' => 3,
            'total_count' => 3, 'verdict' => 'eligible', 'checks' => [], 'staff_status' => 'new', 'computed_at' => now()]);

        $this->postJson('/api/staff/recommendations/'.$make($closed)->id.'/invite')->assertStatus(422)
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'not open for applications'));
        $this->assertSame(0, ScholarshipNotification::count());

        $this->postJson('/api/staff/recommendations/'.$make($open)->id.'/invite')->assertOk();
        $this->assertStringStartsWith('Dear Fe L. Navarro,', ScholarshipNotification::sole()->message);
    }

    public function test_tagging_again_makes_no_copy_says_so_and_keeps_the_atm_details(): void
    {
        $program = $this->program();
        $app = $this->grantee($program, $this->student('2026-71002', 'Gil', 'Ramos'));

        $this->postJson("/api/applications/{$app->id}/scholar-record")->assertOk()
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'Already tagged'));
        $record = ScholarRecord::sole();
        $this->assertTrue($record->has_atm);
        $this->assertSame('yes', $record->atm_funds);
    }

    public function test_payroll_for_a_new_term_waits_for_that_terms_enrollment_check(): void
    {
        $program = $this->program();
        $hana = $this->student('2026-71003', 'Hana', 'Uy');
        $this->grantee($program, $hana);
        $this->assertSame('1st Semester AY 2026-2027', ScholarRecord::sole()->enrollment_period);

        $first = ['period' => '1st Semester AY 2026-2027', 'scholarship_id' => $program->id, 'confirm' => true];
        $this->postJson('/api/payroll/prepare', $first)->assertCreated();

        // 2nd semester: skipped until the 2nd-semester Registrar list is recorded.
        $second = ['period' => '2nd Semester AY 2026-2027', 'scholarship_id' => $program->id];
        $this->postJson('/api/payroll/prepare', $second)->assertOk()
            ->assertJsonPath('rows.0.include', false)
            ->assertJsonPath('rows.0.reason', fn ($r) => str_contains($r, 'Enrollment not yet checked for this period'));

        $list = $this->postJson('/api/staff/enrollment-lists', ['period' => '2nd Semester AY 2026-2027', 'file_name' => 'reg2.csv',
            'rows' => [['student_id' => '2026-71003', 'last_name' => 'Uy', 'first_name' => 'Hana']]])->assertSuccessful()->json('list.id');
        $this->postJson('/api/staff/enrollment/record', ['enrollment_list_id' => $list,
            'items' => [['kind' => 'grantee', 'id' => ScholarRecord::sole()->id, 'enrolled' => true]]])->assertOk();

        $this->postJson('/api/payroll/prepare', $second + ['confirm' => true])->assertCreated();
        $this->assertSame(2, PayrollRecord::count());
    }

    public function test_the_billing_statement_is_made_as_excel_and_pdf_from_the_scholar_records(): void
    {
        $program = $this->program();
        $this->grantee($program, $this->student('2026-71004', 'Ivy', 'Sy', 'Tan'), 'list1.pdf', 'TES-0004');

        $xlsx = $this->postJson('/api/staff/billing-statement', ['scholarship_id' => $program->id, 'school_year' => '2026-2027', 'format' => 'xlsx'])
            ->assertOk()->assertHeader('X-Rows', '1')->assertHeader('X-Missing', '0');
        $this->assertStringStartsWith('PK', $xlsx->getContent());

        $pdf = $this->postJson('/api/staff/billing-statement', ['scholarship_id' => $program->id, 'school_year' => '2026-2027', 'format' => 'pdf'])
            ->assertOk();
        $this->assertStringStartsWith('%PDF', $pdf->getContent());

        $this->postJson('/api/staff/billing-statement', ['scholarship_id' => $program->id, 'school_year' => '2025-2026', 'format' => 'xlsx'])
            ->assertStatus(422);

        Sanctum::actingAs(User::factory()->create(['role' => 'student']));
        $this->postJson('/api/staff/billing-statement', ['scholarship_id' => $program->id, 'school_year' => '2026-2027', 'format' => 'xlsx'])
            ->assertForbidden();
    }

    public function test_a_student_on_an_earlier_list_of_the_program_is_named_in_the_note(): void
    {
        $program = $this->program();
        $jo = $this->student('2026-71005', 'Jo', 'Lim');
        $this->grantee($program, $jo, 'batch1.pdf');

        $upload = $this->postJson('/api/staff/agency-lists', ['scholarship_id' => $program->id, 'agency_name' => 'CHED', 'file_name' => 'batch2.pdf',
            'school_year' => '2026-2027', 'semester' => '2nd Semester',
            'rows' => [['row' => 1, 'student_id' => '2026-71005', 'last_name' => 'Lim', 'first_name' => 'Jo']]])->assertCreated()->json('upload.id');

        $note = AgencyListUpload::find($upload)->details[0]['note'];
        $this->assertStringContainsString('Also on the earlier list batch1.pdf', $note);
    }

    public function test_a_signed_out_api_call_gets_401_not_a_server_error(): void
    {
        $this->app['auth']->forgetGuards();
        $this->get('/api/user')->assertStatus(401);
        $this->getJson('/api/user')->assertStatus(401);
    }

    public function test_a_blank_scan_goes_to_manual_review(): void
    {
        Storage::fake('local');
        Storage::fake('public');
        $user = User::factory()->create(['role' => 'student']);
        Student::create(['user_id' => $user->id, 'student_id' => '2026-71006', 'first_name' => 'Kai', 'last_name' => 'Go']);
        Sanctum::actingAs($user);
        $id = $this->post('/api/student/documents', ['document_type' => 'Certificate of Indigency',
            'file' => UploadedFile::fake()->create('blank.pdf', 20, 'application/pdf')], ['Accept' => 'application/json'])
            ->assertSuccessful()->json('document.id');

        Http::fake(['*/validate-document' => Http::response([
            'ocr_status' => 'ok', 'is_complete' => false, 'has_name_mismatch' => true, 'has_missing_information' => true,
            'has_wrong_document' => false, 'confidence_score' => 0, 'extracted_data' => [],
            'flags' => ['Very little text could be read from this file (blank, unclear or low quality scan).'],
        ])]);

        $this->postJson("/api/documents/{$id}/validate")->assertOk()
            ->assertJsonPath('document.status', 'needs_review')
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'manual review'));
    }
}
