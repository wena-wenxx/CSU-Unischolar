<?php

namespace Tests\Feature;

use App\Models\AiRecommendation;
use App\Models\Document;
use App\Models\ExternalGrantee;
use App\Models\Scholarship;
use App\Models\ScholarshipRequirement;
use App\Models\Student;
use App\Models\User;
use App\Models\ValidationResult;
use App\Support\EligibilityEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
| 20 fictional students whose correct verdict was decided BY HAND before running the engine
| (program: GWA 2.25 or better, income PHP 300,000 or less, 1st-2nd year, College of Agriculture,
| one scholarship only, COR required). The engine must give the same verdict to all 20.
| This checks that the rules are applied correctly; it is not a measure of OAS's real decisions.
*/
class EligibilityLabeledSetTest extends TestCase
{
    use RefreshDatabase;

    private const CAA = 'College of Agriculture and Agri-Industries';

    // [gwa, income, year level, college, holds other, COR state, verified?, expected verdict]
    private const CASES = [
        [1.75, 120000, '1st Year', self::CAA, false, 'validated', true, 'eligible'],
        [2.25, 300000, '2nd Year', self::CAA, false, 'validated', true, 'eligible'],         // exactly on both limits
        [2.26, 150000, '1st Year', self::CAA, false, 'validated', true, 'not_eligible'],     // GWA just over
        [2.00, 300001, '1st Year', self::CAA, false, 'validated', true, 'not_eligible'],     // income just over
        [1.50, 100000, '3rd Year', self::CAA, false, 'validated', true, 'not_eligible'],     // year level
        [1.50, 100000, '1st Year', 'College of Engineering and Geosciences', false, 'validated', true, 'not_eligible'],
        [1.50, 100000, '1st Year', self::CAA, true, 'validated', true, 'not_eligible'],      // holds another
        [null, 100000, '1st Year', self::CAA, false, 'validated', false, 'no_data'],         // no GWA
        [1.50, null, '2nd Year', self::CAA, false, 'validated', false, 'no_data'],           // no income
        [1.50, 100000, null, self::CAA, false, 'validated', false, 'no_data'],               // no year level
        [1.50, 100000, '1st Year', null, false, 'validated', false, 'no_data'],              // no college
        [1.80, 90000, '1st Year', self::CAA, false, 'none', false, 'eligible'],              // COR missing: still eligible, lower score
        [1.80, 90000, '1st Year', self::CAA, false, 'flagged', false, 'eligible'],           // COR flagged: lower score
        [2.75, null, '1st Year', self::CAA, false, 'validated', false, 'not_eligible'],      // a failed rule beats missing data
        [1.25, 50000, '2nd Year', self::CAA, false, 'validated', false, 'eligible'],         // typed, not verified
        [3.00, 500000, '4th Year', 'College of Engineering and Geosciences', true, 'none', true, 'not_eligible'],
        [2.00, 250000, '2nd year', self::CAA, false, 'validated', true, 'eligible'],         // lower-case "year"
        [2.00, 250000, '2nd Year', 'COLLEGE OF AGRICULTURE AND AGRI-INDUSTRIES', false, 'validated', true, 'eligible'],
        [1.00, 0, '1st Year', self::CAA, false, 'validated', true, 'eligible'],              // income 0 is data
        [2.25, 300000, '2nd Year', self::CAA, false, 'needs_review', true, 'eligible'],     // unreadable COR: 0 point
    ];

    public function test_the_engine_agrees_with_20_verdicts_decided_by_hand(): void
    {
        $program = Scholarship::create(['name' => 'Agri Grant (fictional)', 'short_name' => 'AG', 'status' => 'active',
            'application_mode' => 'oas', 'max_gwa' => 2.25, 'max_family_income' => 300000,
            'year_levels' => ['1st Year', '2nd Year'], 'colleges' => [self::CAA], 'one_scholarship_only' => true,
            'application_start' => now()->subDay()->toDateString(), 'application_end' => now()->addMonth()->toDateString()]);
        $cor = ScholarshipRequirement::create(['scholarship_id' => $program->id, 'name' => 'Certificate of Registration (COR)', 'is_required' => true]);

        $students = [];
        foreach (self::CASES as $i => [$gwa, $income, $year, $college, $holds, $corState, $verified]) {
            $user = User::factory()->create(['role' => 'student']);
            $student = Student::create(['user_id' => $user->id, 'student_id' => sprintf('2026-%05d', 91000 + $i),
                'first_name' => 'Case'.($i + 1), 'last_name' => 'Fictional', 'gwa' => $gwa, 'family_income' => $income,
                'year_level' => $year, 'college' => $college,
                'eligibility_verified_at' => $verified ? now() : null]);
            if ($holds) {
                ExternalGrantee::create(['student_id' => $student->id, 'program_name' => 'LGU Grant', 'provider' => 'LGU',
                    'school_year' => \App\Models\ExternalGrantee::currentSchoolYear(), 'status' => 'active', 'match_status' => 'matched']);
            }
            if ($corState !== 'none') {
                $doc = Document::create(['student_id' => $student->id, 'scholarship_requirement_id' => $cor->id,
                    'document_type' => $cor->name, 'original_filename' => 'cor.pdf', 'file_path' => 'documents/cor.pdf', 'status' => $corState]);
                ValidationResult::create(['document_id' => $doc->id, 'confidence_score' => 100, 'flags' => $corState === 'flagged' ? 'Name not found' : '']);
            }
            $students[$i] = $student;
        }

        EligibilityEngine::recalculate();

        $misses = [];
        foreach (self::CASES as $i => $case) {
            $verdict = AiRecommendation::where('student_id', $students[$i]->id)->value('verdict');
            if ($verdict !== $case[7]) {
                $misses[] = 'case '.($i + 1).": expected {$case[7]}, got {$verdict}";
            }
        }
        $this->assertSame([], $misses, (20 - count($misses)).' of 20 agree');
    }
}
