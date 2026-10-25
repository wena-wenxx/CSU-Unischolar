<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Setting;
use App\Models\AiRecommendation;
use App\Models\Document;
use App\Models\ExternalGrantee;
use App\Models\Scholarship;
use App\Models\ScholarshipRequirement;
use App\Models\Student;
use App\Models\User;
use App\Support\EligibilityEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/*
| AI Recommendations: the rule-based score and the safeguards.
| Run: php artisan test
*/
class EligibilityEngineTest extends TestCase
{
    use RefreshDatabase;

    private function student(array $data = []): Student
    {
        static $n = 0;
        $n++;
        $user = User::factory()->create(['role' => 'student', 'email' => "s{$n}@test.local"]);

        return Student::create($data + [
            'user_id' => $user->id,
            'student_id' => sprintf('2026-%05d', 50000 + $n),
            'first_name' => 'Test'.$n,
            'last_name' => 'Student',
            'year_level' => '2nd Year',
            'college' => 'College of Computing and Information Sciences',
            'gwa' => 1.75,
            'family_income' => 100000,
        ]);
    }

    private function scholarship(array $rules = []): Scholarship
    {
        $s = Scholarship::create($rules + [
            'name' => 'Test Program',
            'short_name' => 'TP',
            'application_mode' => 'oas',
            'status' => 'active',
            'application_start' => now()->subDays(5)->toDateString(),
            'application_end' => now()->addDays(30)->toDateString(),
            'max_gwa' => 2.00,
            'max_family_income' => 200000,
        ]);
        ScholarshipRequirement::create(['scholarship_id' => $s->id, 'name' => 'Certificate of Registration (COR)', 'is_required' => true]);

        return $s;
    }

    private function recommendation(Student $student, Scholarship $scholarship): ?AiRecommendation
    {
        return AiRecommendation::where('student_id', $student->id)->where('scholarship_id', $scholarship->id)->first();
    }

    public function test_score_counts_full_points_only_for_checked_data(): void
    {
        $student = $this->student();          // GWA and income typed by the student, not verified
        $program = $this->scholarship();

        EligibilityEngine::recalculate();
        $rec = $this->recommendation($student, $program);

        // GWA ½ + income ½ + one scholarship 1 + COR not uploaded 0 = 2 of 4 points = 50%
        $this->assertSame(50, $rec->score);
        $this->assertSame(3, $rec->met_count);
        $this->assertSame(4, $rec->total_count);
        $this->assertSame('eligible', $rec->verdict);
        $this->assertStringContainsString('3 of 4 checks met', $rec->calculation);
        $this->assertStringContainsString('points earned 2 of 4 = 50%', $rec->calculation);
        $this->assertSame(0.5, collect($rec->checks)->firstWhere('key', 'gwa')['points']);

        // The COR passes the AI check: 3 of 4 points.
        Document::create(['student_id' => $student->id, 'document_type' => 'Certificate of Registration (COR)',
            'original_filename' => 'cor.pdf', 'file_path' => 'x/cor.pdf', 'status' => 'validated']);
        EligibilityEngine::recalculate();
        $this->assertSame(75, $this->recommendation($student, $program)->score);

        // OAS verifies the GWA and income: now every check is fully backed = 100%.
        $student->update(['eligibility_verified_at' => now()->addMinute()]);
        EligibilityEngine::recalculate();
        $this->assertSame(100, $this->recommendation($student->fresh(), $program)->score);
    }

    public function test_an_ai_checked_document_counts_by_how_sure_the_ai_was(): void
    {
        $student = $this->student(['eligibility_verified_at' => now()->addMinute()]);
        $program = $this->scholarship();
        $cor = Document::create(['student_id' => $student->id, 'document_type' => 'Certificate of Registration (COR)',
            'original_filename' => 'cor.pdf', 'file_path' => 'x/cor.pdf', 'status' => 'validated']);
        $cor->validationResult()->create(['is_complete' => true, 'confidence_score' => 84]);

        EligibilityEngine::recalculate();
        $rec = $this->recommendation($student, $program);

        // 1 + 1 + 1 + 0.84 = 3.84 of 4 = 96%
        $this->assertSame(96, $rec->score);
        $this->assertSame(0.84, collect($rec->checks)->firstWhere('key', 'doc:'.$program->requirements()->first()->id)['points']);
    }

    public function test_a_document_counts_only_after_the_ai_check_or_a_staff_check(): void
    {
        $student = $this->student(['eligibility_verified_at' => now()->addMinute()]);
        $program = $this->scholarship();
        $cor = Document::create(['student_id' => $student->id, 'document_type' => 'Certificate of Registration (COR)',
            'original_filename' => 'cor.pdf', 'file_path' => 'x/cor.pdf', 'status' => 'uploaded']);

        EligibilityEngine::recalculate();
        $this->assertSame(75, $this->recommendation($student, $program)->score);   // saved, but not checked: not counted

        $cor->update(['staff_verified_at' => now()]);                                // a person checked it
        EligibilityEngine::recalculate();
        $this->assertSame(100, $this->recommendation($student, $program)->score);
    }

    public function test_a_failed_must_have_rule_is_not_eligible_even_with_a_high_score(): void
    {
        $student = $this->student(['gwa' => 2.50, 'eligibility_verified_at' => now()->addMinute()]);   // worse than 2.00
        $program = $this->scholarship();
        Document::create(['student_id' => $student->id, 'document_type' => 'Certificate of Registration (COR)',
            'original_filename' => 'cor.pdf', 'file_path' => 'x/cor.pdf', 'status' => 'validated']);

        EligibilityEngine::recalculate();
        $rec = $this->recommendation($student, $program);

        $this->assertSame(75, $rec->score);
        $this->assertSame('not_eligible', $rec->verdict);
        $gwa = collect($rec->checks)->firstWhere('key', 'gwa');
        $this->assertSame('not_met', $gwa['result']);
        $this->assertEquals(0, $gwa['points']);
        $this->assertStringContainsString('2.50', $gwa['value']);   // the student's data used is shown
    }

    public function test_missing_data_is_not_enough_data_not_zero(): void
    {
        $student = $this->student(['gwa' => null]);
        $program = $this->scholarship();

        EligibilityEngine::recalculate();

        $this->assertSame('no_data', $this->recommendation($student, $program)->verdict);
    }

    public function test_an_external_scholarship_counts_for_the_one_scholarship_rule(): void
    {
        $student = $this->student();
        $program = $this->scholarship();
        ExternalGrantee::create(['student_id' => $student->id, 'program_name' => 'City Scholarship', 'agency' => 'City LGU',
            'match_status' => 'matched', 'status' => 'active']);

        EligibilityEngine::recalculate();
        $rec = $this->recommendation($student, $program);

        $this->assertSame('not_eligible', $rec->verdict);
        $this->assertStringContainsString('City Scholarship', collect($rec->checks)->firstWhere('key', 'one_scholarship')['value']);

        // The student cannot apply either.
        Sanctum::actingAs($student->user);
        $this->postJson('/api/applications', ['scholarship_id' => $program->id])
            ->assertStatus(422)
            ->assertJsonFragment(['message' => 'You are currently a grantee of City Scholarship (external, City LGU). Only one active scholarship is allowed, so you cannot apply for a new one at this time.']);
    }

    public function test_an_outside_scholarship_from_a_past_school_year_does_not_count(): void
    {
        Setting::put('current_school_year', '2026-2027');
        $student = $this->student();
        $program = $this->scholarship();
        $old = ExternalGrantee::create(['student_id' => $student->id, 'program_name' => 'City Scholarship', 'agency' => 'City LGU',
            'school_year' => '2024-2025', 'match_status' => 'matched', 'status' => 'active']);

        EligibilityEngine::recalculate();
        $this->assertSame('met', collect($this->recommendation($student, $program)->checks)->firstWhere('key', 'one_scholarship')['result']);
        $this->assertTrue($old->fresh()->past_school_year);

        $old->update(['school_year' => '2026-2027']);   // this school year: it counts again
        EligibilityEngine::recalculate();
        $this->assertSame('not_eligible', $this->recommendation($student, $program)->verdict);
    }

    public function test_staff_correct_a_wrong_gwa_from_the_documents(): void
    {
        $student = $this->student(['gwa' => 1.50]);   // typed by the student
        $program = $this->scholarship();               // GWA 2.00 or better
        Sanctum::actingAs(User::factory()->create(['role' => 'staff']));

        $this->postJson("/api/staff/students/{$student->id}/eligibility", ['verified' => true, 'gwa' => 2.75])
            ->assertOk()
            ->assertJsonFragment(['message' => 'Corrected (GWA 1.50 → 2.75) and GWA and family income marked as verified.']);

        $rec = $this->recommendation($student, $program);
        $this->assertSame('not_eligible', $rec->verdict);                       // 2.75 fails the 2.00 rule
        $this->assertTrue(EligibilityEngine::dataVerified($student->fresh()));
        $this->assertStringContainsString('GWA 1.50 → 2.75', ActivityLog::where('action', 'eligibility.corrected')->value('description'));
    }

    public function test_rule_changes_keep_who_when_and_old_and_new_values(): void
    {
        $student = $this->student();
        $program = $this->scholarship();
        $staff = User::factory()->create(['role' => 'staff', 'name' => 'Test Staff']);
        Sanctum::actingAs($staff);

        $this->putJson("/api/scholarships/{$program->id}", ['max_gwa' => 1.50, 'rules_source' => 'Sample memo (fictional), section 2'])->assertOk();

        $program->refresh();
        $this->assertSame($staff->id, $program->rules_updated_by);
        $this->assertNotNull($program->rules_updated_at);
        $this->assertStringContainsString('highest GWA: 2.00 → 1.50', ActivityLog::where('action', 'scholarship.rules_changed')->value('description'));
        // Scores follow the new rule right away: GWA 1.75 no longer meets 1.50.
        $this->assertSame('not_eligible', $this->recommendation($student, $program)->verdict);
    }

    /*
    | Hand-calculated cases (written out in documentation/AUDIT_REPORT.md, section D).
    | Program: GWA 2.00 or better, income PHP 200,000 or less, one scholarship only, 1 required document (COR).
    */
    public function test_hand_calculated_cases(): void
    {
        $program = $this->scholarship();
        $verified = ['eligibility_verified_at' => now()->addMinute()];

        // A: exactly at both limits (2.00 and PHP 200,000 count as met), verified, COR passed the AI check at 100.
        $a = $this->student(['gwa' => 2.00, 'family_income' => 200000] + $verified);
        Document::create(['student_id' => $a->id, 'document_type' => 'Certificate of Registration (COR)',
            'original_filename' => 'cor.pdf', 'file_path' => 'x/a.pdf', 'status' => 'validated']);
        // B: just over the GWA limit (2.01), verified: fails a must-have rule. 0 + 1 + 1 + 0 = 2 of 4 = 50%.
        $b = $this->student(['gwa' => 2.01] + $verified);
        // C: typed by the student (not verified), COR flagged by the AI: ½ + ½ + 1 + 0 = 2 of 4 = 50%.
        $c = $this->student();
        Document::create(['student_id' => $c->id, 'document_type' => 'Certificate of Registration (COR)',
            'original_filename' => 'cor.pdf', 'file_path' => 'x/c.pdf', 'status' => 'flagged']);
        // D: no GWA entered: not enough data (unknown is not a fail). 0 + ½ + 1 + 0 = 1.5 of 4 = 38% (37.5 rounded).
        $d = $this->student(['gwa' => null]);

        EligibilityEngine::recalculate();

        $expect = [[$a, 100, 'eligible'], [$b, 50, 'not_eligible'], [$c, 50, 'eligible'], [$d, 38, 'no_data']];
        foreach ($expect as [$student, $score, $verdict]) {
            $rec = $this->recommendation($student, $program);
            $this->assertSame([$score, $verdict], [$rec->score, $rec->verdict], "student {$student->student_id}: {$rec->calculation}");
        }
    }

    public function test_staff_decisions_are_kept_when_scores_are_recalculated(): void
    {
        $student = $this->student();
        $program = $this->scholarship();
        EligibilityEngine::recalculate();
        $this->recommendation($student, $program)->update(['staff_status' => 'dismissed']);

        $student->update(['gwa' => 1.25]);
        EligibilityEngine::recalculate();

        $this->assertSame('dismissed', $this->recommendation($student, $program)->staff_status);
    }

    public function test_staff_must_give_a_reason_to_go_against_the_ai_and_nothing_is_approved(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $student = $this->student(['family_income' => 900000]);   // over the ceiling
        $program = $this->scholarship();
        EligibilityEngine::recalculate();
        $rec = $this->recommendation($student, $program);

        Sanctum::actingAs($staff);
        $this->postJson("/api/staff/recommendations/{$rec->id}/add")->assertStatus(422);

        $this->postJson("/api/staff/recommendations/{$rec->id}/add", ['note' => 'Income changed this year; ITR checked.'])
            ->assertStatus(201);

        // Only a DRAFT application: no approval, no grantee record.
        $this->assertDatabaseHas('applications', ['student_id' => $student->id, 'scholarship_id' => $program->id, 'status' => 'draft']);
        $this->assertDatabaseMissing('scholar_records', ['student_id' => $student->id]);
        $this->assertDatabaseHas('recommendation_logs', ['ai_recommendation_id' => $rec->id, 'action' => 'added']);
    }

    public function test_staff_get_next_steps_and_the_students_other_programs(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $student = $this->student();
        $program = $this->scholarship();
        $strict = $this->scholarship(['name' => 'Strict Program', 'short_name' => 'SP', 'max_gwa' => 1.25]);
        EligibilityEngine::recalculate();

        Sanctum::actingAs($staff);
        $data = $this->getJson('/api/staff/recommendations/'.$this->recommendation($student, $program)->id)->assertOk()->json();

        // 75%: meets the rules, the COR is missing.
        $this->assertSame('Qualifies, a few documents left', $data['next_steps']['title']);
        $this->assertStringContainsString('COR', implode(' ', $data['next_steps']['steps']));
        $this->assertSame('Strict Program', $data['other_matches'][0]['program']);
        $this->assertSame('not_eligible', $data['other_matches'][0]['verdict']);

        $failed = $this->getJson('/api/staff/recommendations/'.$this->recommendation($student, $strict)->id)->json();
        $this->assertSame('Not eligible for this program', $failed['next_steps']['title']);
        $this->assertStringContainsString('1.25', $failed['next_steps']['steps'][0]);
    }

    public function test_students_see_a_checklist_without_a_percentage_and_never_failed_programs(): void
    {
        $student = $this->student(['family_income' => null]);
        $this->scholarship(['max_family_income' => null]);   // only the GWA rule + COR
        $this->scholarship(['name' => 'Strict Program', 'short_name' => 'SP', 'max_gwa' => 1.25, 'max_family_income' => null]);
        $this->scholarship(['name' => 'Income Program', 'short_name' => 'IP']);   // needs the income

        Sanctum::actingAs($student->user);
        $matches = $this->getJson('/api/student/matches')->assertOk()->json('matches');

        $this->assertSame(['Test Program', 'Income Program'], array_column($matches, 'name'));   // best first; Strict hidden
        $this->assertSame('almost', $matches[0]['status']);
        $this->assertSame('Upload your Certificate of Registration (COR)', $matches[0]['todo'][0]['text']);
        $this->assertSame('need_info', $matches[1]['status']);
        $this->assertSame("Enter your family's yearly income on My Profile", $matches[1]['todo'][0]['text']);
        $this->assertArrayNotHasKey('score', $matches[0]);

        // An uploaded but unchecked COR: the student is told to press Check with AI.
        Document::create(['student_id' => $student->id, 'document_type' => 'Certificate of Registration (COR)',
            'original_filename' => 'cor.pdf', 'file_path' => 'x/cor.pdf', 'status' => 'uploaded']);
        $first = $this->getJson('/api/student/matches')->json('matches.0');
        $this->assertSame('check', $first['todo'][0]['kind']);

        // Validated: ready to apply.
        Document::where('student_id', $student->id)->update(['status' => 'validated']);
        $this->assertSame('ready', $this->getJson('/api/student/matches')->json('matches.0.status'));
    }
}
