<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\ScholarRecord;
use App\Models\Scholarship;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/*
| The CHED TES billing file processed as an approved list (fictional people).
*/
class TesBillingListTest extends TestCase
{
    use RefreshDatabase;

    private function student(string $id, string $first, string $last): Student
    {
        $user = User::factory()->create(['role' => 'student']);

        return Student::create(['user_id' => $user->id, 'student_id' => $id, 'first_name' => $first, 'last_name' => $last]);
    }

    private function tes(): Scholarship
    {
        return Scholarship::create(['name' => 'CHED Tertiary Education Subsidy (TES)', 'short_name' => 'TES', 'provider' => 'CHED',
            'status' => 'active', 'application_mode' => 'oas', 'amount' => 20000]);
    }

    private function row(int $n, string $id, string $first, string $last): array
    {
        return ['row' => $n, 'student_id' => $id, 'first_name' => $first, 'last_name' => $last,
            'control_number' => sprintf('%05d', $n), 'award_number' => sprintf('TES-20990101-990000000000-%06d', 900000 + $n),
            'batch' => '2', 'amount' => '10000.00', 'total_amount' => '10000.00'];
    }

    private function upload(Scholarship $tes, array $rows, array $extra = [])
    {
        return $this->postJson('/api/staff/agency-lists', [
            'scholarship_id' => $tes->id, 'agency_name' => 'CHED', 'file_name' => 'tes-billing-batch2.pdf',
            'school_year' => '2026-2027', 'semester' => '1st Semester', 'batch' => 'Batch 2', 'rows' => $rows,
        ] + $extra);
    }

    public function test_the_award_details_are_saved_and_follow_the_student_to_the_scholar_record(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'staff']));
        $tes = $this->tes();
        $ana = $this->student('221-00101', 'Ana Marie', 'Dela Peña');
        $app = Application::create(['student_id' => $ana->id, 'scholarship_id' => $tes->id, 'status' => 'forwarded', 'submitted_at' => now()]);

        // "DELA PENA" on the scan (no Ñ) is the same person.
        $this->upload($tes, [$this->row(1, '221-00101', 'ANA MARIE', 'DELA PENA')])->assertCreated();

        $app->refresh();
        $this->assertSame('approved', $app->status);
        $this->assertSame('00001', $app->agency_control_number);
        $this->assertSame('TES-20990101-990000000000-900001', $app->agency_award_number);
        $this->assertEquals(10000, $app->agency_amount);

        $this->postJson("/api/applications/{$app->id}/verify-enrollment", ['currently_enrolled' => true])->assertOk();
        $this->postJson("/api/applications/{$app->id}/scholar-record")->assertCreated();
        $record = ScholarRecord::first();
        $this->assertSame('TES-20990101-990000000000-900001', $record->award_number);
        $this->assertEquals(10000, $record->award_amount);

        // Payroll uses the billing amount (10,000), not the program's 20,000.
        $preview = $this->postJson('/api/payroll/prepare', ['period' => '1st Semester AY 2026-2027', 'scholarship_id' => $tes->id])
            ->assertOk()->json('summary');
        $this->assertEquals(10000, $preview['total_amount']);
    }

    public function test_a_matching_student_id_with_a_different_name_is_not_approved(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'staff']));
        $tes = $this->tes();
        $ben = $this->student('221-00102', 'Ben', 'Torres');
        $app = Application::create(['student_id' => $ben->id, 'scholarship_id' => $tes->id, 'status' => 'forwarded', 'submitted_at' => now()]);

        $response = $this->upload($tes, [$this->row(1, '221-00102', 'CARLO JAY', 'SALAZAR')])->assertCreated();

        $this->assertSame('check', $response->json('upload.details.0.result'));
        $this->assertStringContainsString('belongs to Ben Torres', $response->json('upload.details.0.note'));
        $this->assertSame(1, $response->json('upload.error_count'));
        $this->assertSame('forwarded', $app->fresh()->status);
    }

    public function test_the_same_list_processed_twice_needs_a_confirmation(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'staff']));
        $tes = $this->tes();
        $this->student('221-00103', 'Diana', 'Ramos');
        $rows = [$this->row(1, '221-00103', 'DIANA', 'RAMOS')];

        $this->upload($tes, $rows)->assertCreated();
        $this->upload($tes, $rows)->assertStatus(409);
        $this->upload($tes, $rows, ['confirmed_duplicate' => true])->assertCreated()
            ->assertJsonPath('upload.already_count', 1);
    }

    public function test_a_list_without_student_ids_matches_only_one_of_a_kind_names(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'staff']));
        $tes = $this->tes();
        $ana = $this->student('221-00201', 'Ana', 'Ibañez');
        $this->student('221-00202', 'Mark', 'Reyes');
        $this->student('221-00203', 'Mark', 'Reyes');          // two students with the same name
        Application::create(['student_id' => $ana->id, 'scholarship_id' => $tes->id, 'status' => 'complete', 'submitted_at' => now()]);

        $response = $this->upload($tes, [
            ['row' => 1, 'last_name' => 'IBANEZ', 'first_name' => 'ANA'],
            ['row' => 2, 'last_name' => 'REYES', 'first_name' => 'MARK'],
            ['row' => 3, 'last_name' => 'NOBODY', 'first_name' => 'HERE'],
        ])->assertCreated();

        $details = collect($response->json('upload.details'))->keyBy('row');
        $this->assertSame('approved', $details[1]['result']);
        $this->assertStringContainsString('Matched by name', $details[1]['note']);
        $this->assertSame('unmatched', $details[2]['result']);     // ambiguous: never guessed
        $this->assertSame('unmatched', $details[3]['result']);
    }
}
