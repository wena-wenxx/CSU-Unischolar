<?php

namespace Tests\Feature;

use App\Models\PayrollRecord;
use App\Models\ScholarRecord;
use App\Models\Scholarship;
use App\Models\Setting;
use App\Models\Student;
use App\Models\User;
use App\Support\GeneralPayrollForm;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/*
| General Payroll (General Form No. 6-A) and staff document verification.
| Run: php artisan test
*/
class GeneralPayrollFormTest extends TestCase
{
    use RefreshDatabase;

    /** @return int[] payroll ids */
    private function payroll(int $withAtm, int $withoutAtm, string $period = '1st Semester AY 2026-2027'): array
    {
        $program = Scholarship::create(['name' => 'CHED Tertiary Education Subsidy (TES)', 'short_name' => 'TES', 'amount' => 10000, 'status' => 'active']);
        $ids = [];
        foreach (range(1, $withAtm + $withoutAtm) as $i) {
            $user = User::factory()->create(['role' => 'student']);
            $student = Student::create(['user_id' => $user->id, 'student_id' => sprintf('2026-%05d', 60000 + $i),
                'first_name' => 'Ana'.$i, 'middle_name' => 'Reyes', 'last_name' => sprintf('Zeta%02d', 30 - $i)]);
            $record = ScholarRecord::create(['student_id' => $student->id, 'scholarship_id' => $program->id, 'status' => 'active',
                'currently_enrolled' => true, 'has_atm' => $i <= $withAtm]);
            $ids[] = PayrollRecord::create(['scholar_record_id' => $record->id, 'amount' => 10000, 'period' => $period, 'status' => 'ready'])->id;
        }

        return $ids;
    }

    public function test_sheets_follow_the_oas_form(): void
    {
        $staff = User::factory()->create(['role' => 'staff', 'name' => 'Test Staff']);
        Setting::put('payroll_director_name', 'Test Director');
        $ids = $this->payroll(17, 2);

        $form = GeneralPayrollForm::build($ids, ['voucher' => 'OR#0146829'], $staff);

        // 17 with ATM -> sheets of 15 + 2; 2 without ATM -> 1 sheet
        $this->assertSame(['WITH ATM', 'WITH ATM', 'WITHOUT ATM'], array_column($form['sheets'], 'atm_label'));
        $this->assertSame([1, 2, 3], array_column($form['sheets'], 'sheet_no'));
        $this->assertCount(15, $form['sheets'][0]['rows']);
        $this->assertSame(150000.0, $form['sheets'][0]['total']);
        $this->assertFalse($form['sheets'][0]['is_last']);
        $this->assertSame(170000.0, $form['sheets'][1]['grand_total']);
        $this->assertSame('TES GRANTEES BATCH 1', $form['batch']);
        $this->assertSame('1ST SEM. 2026-2027', $form['first_sem_label']);
        $this->assertSame(10000.0, $form['sheets'][0]['rows'][0]['first_sem']);
        $this->assertNull($form['sheets'][0]['rows'][0]['second_sem']);
        $this->assertSame('ZETA13, ANA17 R.', $form['sheets'][0]['rows'][0]['name']);   // A to Z within WITH ATM, "LAST, FIRST M."
        $this->assertSame('Test Staff', $form['signatories']['prepared']['name']);
        $this->assertSame('Test Director', $form['signatories']['director']['name']);
        $this->assertSame('OAS Director', $form['signatories']['director']['title']);
    }

    public function test_second_semester_goes_in_the_second_column_and_word_downloads(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $ids = $this->payroll(1, 0, '2nd Semester AY 2026-2027');

        $form = GeneralPayrollForm::build($ids, ['split_atm' => false], $staff);
        $this->assertNull($form['sheets'][0]['rows'][0]['first_sem']);
        $this->assertSame(10000.0, $form['sheets'][0]['rows'][0]['second_sem']);

        Sanctum::actingAs($staff);
        $response = $this->post('/api/payroll/form/docx', ['ids' => $ids, 'batch' => 'TES Batch 1.1']);
        $response->assertOk();
        $this->assertStringContainsString('wordprocessingml', $response->headers->get('Content-Type'));
        $this->assertStringStartsWith('PK', $response->getContent());   // a .docx is a zip file
    }

    public function test_one_form_is_for_one_program_and_one_period(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $ids = array_merge($this->payroll(1, 0, '1st Semester AY 2026-2027'),
            [PayrollRecord::create(['scholar_record_id' => ScholarRecord::first()->id, 'amount' => 10000, 'period' => '2nd Semester AY 2026-2027', 'status' => 'ready'])->id]);

        Sanctum::actingAs($staff);
        $this->postJson('/api/payroll/form', ['ids' => $ids])->assertStatus(422);

        Sanctum::actingAs(User::factory()->create(['role' => 'student']));
        $this->postJson('/api/payroll/form', ['ids' => [$ids[0]]])->assertStatus(403);
    }

    public function test_only_staff_can_mark_a_document_verified(): void
    {
        $user = User::factory()->create(['role' => 'student']);
        $student = Student::create(['user_id' => $user->id, 'student_id' => '2026-70001', 'first_name' => 'A', 'last_name' => 'B']);
        $document = \App\Models\Document::create(['student_id' => $student->id, 'document_type' => 'Valid ID',
            'original_filename' => 'id.pdf', 'file_path' => 'x/id.pdf', 'status' => 'uploaded']);

        Sanctum::actingAs($user);
        $this->postJson("/api/documents/{$document->id}/verify", ['verified' => true])->assertStatus(403);

        $staff = User::factory()->create(['role' => 'staff']);
        Sanctum::actingAs($staff);
        $this->postJson("/api/documents/{$document->id}/verify", ['verified' => true, 'note' => 'PhilSys Check QR'])->assertOk();
        $this->assertNotNull($document->fresh()->staff_verified_at);
        $this->assertSame('PhilSys Check QR', $document->fresh()->staff_verify_note);
    }
}
