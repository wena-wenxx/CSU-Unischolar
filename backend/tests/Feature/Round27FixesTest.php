<?php

namespace Tests\Feature;

use App\Models\AiRecommendation;
use App\Models\Application;
use App\Models\ContactMessage;
use App\Models\Scholarship;
use App\Models\Student;
use App\Models\User;
use App\Support\EligibilityEngine;
use App\Support\GeneralPayrollForm;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/* Round 27: recommendations for every student, Registrar list rows, payroll signatories, messages order (fictional names). */
class Round27FixesTest extends TestCase
{
    use RefreshDatabase;

    private function student(string $id, string $last): Student
    {
        $user = User::factory()->create(['role' => 'student']);

        return Student::create(['user_id' => $user->id, 'student_id' => $id, 'first_name' => 'Mira', 'last_name' => $last,
            'course' => 'BSIT', 'year_level' => '2nd Year', 'gwa' => 1.8, 'family_income' => 120000]);
    }

    public function test_recommendations_list_every_student_even_after_only_one_student_was_rescored(): void
    {
        Scholarship::create(['name' => 'Merit Grant', 'short_name' => 'MG', 'status' => 'active', 'application_mode' => 'oas', 'max_gwa' => 2.0]);
        $one = $this->student('2026-80001', 'Santos');
        $this->student('2026-80002', 'Reyes');
        $this->student('2026-80003', 'Lim');

        // Records cleared, then one student's profile change re-scores only that student (the old trap).
        AiRecommendation::query()->delete();
        EligibilityEngine::recalculate([$one->id]);
        $this->assertSame(1, AiRecommendation::distinct()->count('student_id'));

        Sanctum::actingAs(User::factory()->create(['role' => 'staff']));
        $this->getJson('/api/staff/recommendations?view=all')->assertOk();
        $this->assertSame(3, AiRecommendation::distinct()->count('student_id'));
    }

    public function test_every_row_of_the_registrar_list_is_shown_with_what_it_means(): void
    {
        $waiting = $this->student('2026-80011', 'Santos');
        $this->student('2026-80012', 'Reyes');
        $program = Scholarship::create(['name' => 'Merit Grant', 'short_name' => 'MG', 'status' => 'active', 'application_mode' => 'oas']);
        Application::create(['student_id' => $waiting->id, 'scholarship_id' => $program->id, 'status' => 'approved', 'enrollment_verified' => false]);
        Sanctum::actingAs(User::factory()->create(['role' => 'staff']));

        $rows = [['student_id' => '2026-80011', 'last_name' => 'SANTOS'], ['student_id' => '2026-80012', 'last_name' => 'REYES'],
            ['student_id' => '2026-80013', 'last_name' => 'OTHER'], ['student_id' => '2026-80012', 'last_name' => 'REYES']];
        $list = $this->postJson('/api/staff/enrollment-lists', ['period' => '1st Semester AY 2026-2027', 'file_name' => 'reg.xlsx', 'rows' => $rows])
            ->assertCreated()->json('list.id');

        $data = $this->getJson("/api/staff/enrollment-lists/{$list}/rows")->assertOk()->json();
        $this->assertSame(3, $data['summary']['total']);   // the repeated row is kept once
        $this->assertSame(['waiting', 'no_grant', 'not_in_system'], array_column($data['rows'], 'result'));
        // Looking at the list records nobody as enrolled.
        $this->assertFalse((bool) Application::first()->enrollment_verified);
    }

    public function test_payroll_signatories_print_like_the_oas_form(): void
    {
        $this->assertSame('AIRISH PELEMENIANO - JUMONONG, MSc', GeneralPayrollForm::signName('Airish Pelemeniano - Jumonong, MSc'));
        $this->assertSame('SHEILA RAE E. PERMANES, PhD', GeneralPayrollForm::signName('Sheila Rae E. Permanes, PhD'));
        $this->assertSame('BENNAJIB J. ABUBAKAR', GeneralPayrollForm::signName('Bennajib J. Abubakar'));
        foreach (\App\Models\Setting::payrollSignatories() as $slot => $person) {
            $this->assertNotEmpty($person['name'], "$slot has a name");
            $this->assertNotEmpty($person['title'], "$slot has a position");
        }
    }

    public function test_student_messages_are_listed_newest_first(): void
    {
        $student = $this->student('2026-80021', 'Santos');
        $old = ContactMessage::create(['student_id' => $student->id, 'topic' => 'other', 'subject' => 'Old', 'message' => 'First', 'status' => 'open']);
        $old->forceFill(['created_at' => now()->subDays(2)])->save();
        ContactMessage::create(['student_id' => $student->id, 'topic' => 'other', 'subject' => 'New', 'message' => 'Second', 'status' => 'open']);
        Sanctum::actingAs(User::factory()->create(['role' => 'staff']));

        $this->assertSame(['New', 'Old'], array_column($this->getJson('/api/staff/messages?status=open')->json('messages'), 'subject'));
    }
}
