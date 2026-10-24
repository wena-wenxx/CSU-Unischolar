<?php

namespace Tests\Feature;

use App\Models\ProgramFit;
use App\Models\Scholarship;
use App\Models\Student;
use App\Models\User;
use App\Support\EligibilityEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/*
| Round 12: AI field fit (the AI service is faked here) and the memo reader proxy.
| Run: php artisan test
*/
class AiFitTest extends TestCase
{
    use RefreshDatabase;

    private function setUpPrograms(bool $fakeFit = true): array
    {
        $user = User::factory()->create(['role' => 'student']);
        $student = Student::create(['user_id' => $user->id, 'student_id' => '2026-90001', 'first_name' => 'Ana', 'last_name' => 'Cruz',
            'course' => 'Bachelor of Science in Agriculture', 'college' => 'College of Agriculture', 'year_level' => '2nd Year',
            'gwa' => 1.75, 'family_income' => 100000]);
        $oas = Scholarship::create(['name' => 'Need Grant', 'short_name' => 'NG', 'status' => 'active', 'application_mode' => 'oas',
            'max_gwa' => 2.5, 'application_start' => now()->subDay(), 'application_end' => now()->addMonth()]);
        $agency = Scholarship::create(['name' => 'Farm Agency Grant', 'short_name' => 'FAG', 'status' => 'active',
            'application_mode' => 'agency_direct', 'description' => 'For agriculture and fisheries students.']);

        if (!$fakeFit) {
            return [$student, $oas, $agency];
        }
        Http::fake(['*/fit-scores' => function ($request) use ($student, $oas, $agency) {
            // The texts sent to the AI include the student's course and the program description.
            $this->assertStringContainsString('Agriculture', $request['students'][0]['text']);

            return Http::response(['success' => true, 'method' => 'tfidf', 'scores' => [
                ['student_id' => $student->id, 'program_id' => $oas->id, 'level' => 'any', 'similarity' => 0.0, 'fields' => [], 'words' => []],
                ['student_id' => $student->id, 'program_id' => $agency->id, 'level' => 'high', 'similarity' => 0.4, 'fields' => ['Agriculture'], 'words' => ['agricultur']],
            ]]);
        }]);

        return [$student, $oas, $agency];
    }

    public function test_fit_is_saved_and_shown_to_staff_and_students(): void
    {
        [$student, $oas, $agency] = $this->setUpPrograms();

        $result = EligibilityEngine::recalculate();
        $this->assertTrue($result['fit']['ok']);
        $this->assertSame('high', ProgramFit::where('scholarship_id', $agency->id)->value('level'));

        // Students: the OAS program is listed (rules met), the agency program for their field under "also fits".
        Sanctum::actingAs($student->user);
        $data = $this->getJson('/api/student/matches')->assertOk()->json();
        $this->assertSame('any', $data['matches'][0]['fit']['level']);
        $this->assertSame('Farm Agency Grant', $data['also_fits'][0]['name']);
        $this->assertFalse($data['has_interests']);

        // Staff: the Why? window shows the fit and the agency program for the student's field.
        Sanctum::actingAs(User::factory()->create(['role' => 'staff']));
        $rec = \App\Models\AiRecommendation::where('scholarship_id', $oas->id)->first();
        $why = $this->getJson("/api/staff/recommendations/{$rec->id}")->assertOk()->json();
        $this->assertSame('Open to all fields', $why['fit']['label']);
        $this->assertSame('Farm Agency Grant', $why['field_matches'][0]['program']);
    }

    public function test_fit_never_changes_eligibility_and_the_ai_being_off_is_fine(): void
    {
        [$student, $oas] = $this->setUpPrograms(false);
        Http::fake(['*/fit-scores' => Http::response('down', 500)]);

        $result = EligibilityEngine::recalculate();
        $this->assertFalse($result['fit']['ok']);
        $this->assertSame('eligible', \App\Models\AiRecommendation::where('scholarship_id', $oas->id)->value('verdict'));
    }

    public function test_students_can_save_their_field_and_goals(): void
    {
        [$student] = $this->setUpPrograms();
        Sanctum::actingAs($student->user);

        $this->patchJson('/api/profile/interests', ['interests' => 'I want to work in crop research.'])->assertOk();
        $this->assertSame('I want to work in crop research.', $student->fresh()->interests);
        $this->patchJson('/api/profile/interests', ['interests' => str_repeat('x', 301)])->assertStatus(422);
    }

    public function test_memo_reader_is_for_staff_and_saves_nothing(): void
    {
        Http::fake(['*/suggest-rules' => Http::response(['success' => true, 'method' => 'patterns', 'warnings' => [],
            'suggestions' => ['max_gwa' => ['value' => 2.25, 'quote' => 'GWA of 2.25 or better.', 'by' => 'pattern', 'check' => false]]])]);
        $program = Scholarship::create(['name' => 'Memo Program', 'short_name' => 'MP', 'status' => 'active']);

        Sanctum::actingAs(User::factory()->create(['role' => 'student']));
        $this->postJson('/api/staff/ai/suggest-rules', ['text' => 'Applicants need a GWA of 2.25 or better.'])->assertStatus(403);

        Sanctum::actingAs(User::factory()->create(['role' => 'staff']));
        $this->postJson('/api/staff/ai/suggest-rules', ['text' => ''])->assertStatus(422);
        $this->postJson('/api/staff/ai/suggest-rules', ['text' => 'Applicants need a GWA of 2.25 or better.'])
            ->assertOk()->assertJsonPath('suggestions.max_gwa.value', 2.25);
        $this->assertNull($program->fresh()->max_gwa);   // nothing saved
    }
}
