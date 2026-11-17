<?php

namespace Tests\Feature;

use App\Models\AiRecommendation;
use App\Models\Scholarship;
use App\Models\ScholarshipNotification;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RecommendationNotificationTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $studentUser = User::factory()->create(['role' => 'student']);
        $student = Student::create([
            'user_id' => $studentUser->id,
            'student_id' => '2026-70001',
            'first_name' => 'Ana',
            'last_name' => 'Cruz',
            'course' => 'Bachelor of Science in Information Technology',
            'college' => 'College of Computing and Information Sciences',
            'year_level' => '4th Year',
            'gwa' => 1.75,
            'family_income' => 100000,
        ]);
        $scholarship = Scholarship::create([
            'name' => 'Technology Grant',
            'short_name' => 'TG',
            'status' => 'active',
            'application_mode' => 'oas',
            'application_start' => now()->subDay(),
            'application_end' => now()->addMonth(),
        ]);
        $recommendation = AiRecommendation::create([
            'student_id' => $student->id,
            'scholarship_id' => $scholarship->id,
            'score' => 80,
            'met_count' => 2,
            'total_count' => 3,
            'verdict' => 'eligible',
            'checks' => [
                ['label' => 'GWA', 'result' => 'met'],
                ['label' => 'Income', 'result' => 'met'],
                ['label' => 'Documents', 'result' => 'no_data'],
            ],
            'staff_status' => 'new',
            'computed_at' => now(),
        ]);

        return [$staff, $studentUser, $student, $scholarship, $recommendation];
    }

    public function test_staff_can_send_a_personalized_notification_without_creating_an_application(): void
    {
        [$staff, $studentUser, $student, $scholarship, $recommendation] = $this->fixture();
        Sanctum::actingAs($staff);

        $this->postJson('/api/staff/recommendations/notifications', [
            'scholarship_id' => $scholarship->id,
            'recommendation_ids' => [$recommendation->id],
            'message' => 'Dear [Student Name], review [Scholarship Name].',
            'skip_duplicates' => true,
        ])->assertOk()->assertJsonPath('sent', 1);

        $this->assertDatabaseHas('scholarship_notifications', [
            'student_id' => $student->id,
            'scholarship_id' => $scholarship->id,
            'message' => 'Dear Ana Cruz, review Technology Grant.',
        ]);
        $this->assertDatabaseMissing('applications', ['student_id' => $student->id, 'scholarship_id' => $scholarship->id]);
        $this->assertDatabaseMissing('scholar_records', ['student_id' => $student->id, 'scholarship_id' => $scholarship->id]);

        Sanctum::actingAs($studentUser);
        $this->getJson('/api/student/notifications')
            ->assertOk()
            ->assertJsonPath('opportunities.0.scholarship', 'Technology Grant')
            ->assertJsonPath('opportunities.0.message', 'Dear Ana Cruz, review Technology Grant.');
    }

    public function test_duplicate_notifications_are_reported_and_skipped(): void
    {
        [$staff, , $student, $scholarship, $recommendation] = $this->fixture();
        ScholarshipNotification::create([
            'student_id' => $student->id,
            'scholarship_id' => $scholarship->id,
            'sender_id' => $staff->id,
            'ai_recommendation_id' => $recommendation->id,
            'message' => 'Already sent.',
            'sent_at' => now(),
        ]);

        Sanctum::actingAs($staff);
        $this->postJson('/api/staff/recommendations/notifications/check', [
            'scholarship_id' => $scholarship->id,
            'recommendation_ids' => [$recommendation->id],
        ])->assertOk()->assertJsonCount(1, 'duplicates')->assertJsonPath('sendable', 0);

        $this->postJson('/api/staff/recommendations/notifications', [
            'scholarship_id' => $scholarship->id,
            'recommendation_ids' => [$recommendation->id],
            'message' => 'A second message.',
            'skip_duplicates' => true,
        ])->assertOk()->assertJsonPath('sent', 0)->assertJsonPath('skipped_duplicates', 1);

        $this->assertSame(1, ScholarshipNotification::count());
    }

    public function test_non_staff_users_cannot_send_recommendation_notifications(): void
    {
        [, $studentUser, , $scholarship, $recommendation] = $this->fixture();
        Sanctum::actingAs($studentUser);

        $this->postJson('/api/staff/recommendations/notifications', [
            'scholarship_id' => $scholarship->id,
            'recommendation_ids' => [$recommendation->id],
            'message' => 'Not allowed.',
        ])->assertForbidden();
    }

    public function test_staff_can_filter_recommendations_by_student_profile(): void
    {
        [$staff, , , $scholarship, $recommendation] = $this->fixture();
        $otherUser = User::factory()->create(['role' => 'student']);
        $other = Student::create([
            'user_id' => $otherUser->id,
            'student_id' => '2026-70002',
            'first_name' => 'Ben',
            'last_name' => 'Lee',
            'course' => 'Bachelor of Science in Biology',
            'college' => 'College of Mathematics and Natural Sciences',
            'year_level' => '2nd Year',
        ]);
        AiRecommendation::create([
            'student_id' => $other->id,
            'scholarship_id' => $scholarship->id,
            'score' => 80,
            'met_count' => 2,
            'total_count' => 3,
            'verdict' => 'eligible',
            'checks' => [['label' => 'GWA', 'result' => 'met']],
            'staff_status' => 'new',
            'computed_at' => now(),
        ]);

        Sanctum::actingAs($staff);
        $this->getJson('/api/staff/recommendations?college='.urlencode($recommendation->student->college))
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.student.student_id', '2026-70001');
    }
}
