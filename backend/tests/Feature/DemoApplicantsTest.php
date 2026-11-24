<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\Document;
use App\Models\Scholarship;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/* demo:applicants gives demo students (fictional) a submitted application; real students are never used. */
class DemoApplicantsTest extends TestCase
{
    use RefreshDatabase;

    public function test_demo_students_get_a_submitted_application_with_every_required_document(): void
    {
        Storage::fake('local');
        config(['demo.reset_allowed' => true]);
        $program = Scholarship::create(['name' => 'Tertiary Grant', 'short_name' => 'TG', 'status' => 'active', 'application_mode' => 'oas']);
        foreach (['Certificate of Registration (COR)', 'Certificate of Grades', 'Valid ID'] as $name) {
            $program->requirements()->create(['name' => $name, 'is_required' => true]);
        }
        foreach (range(1, 6) as $i) {
            $user = User::factory()->create(['role' => 'student']);
            Student::create(['user_id' => $user->id, 'student_id' => "2026-7000{$i}", 'first_name' => 'Demo', 'last_name' => "Person{$i}",
                'course' => 'BSIT', 'year_level' => '1st Year', 'is_demo' => $i <= 5]);
        }

        $this->artisan('demo:applicants', ['count' => 4, '--program' => 'TG'])->assertExitCode(0);

        $this->assertSame(4, Application::where('status', 'submitted')->count());
        $this->assertSame(12, Document::count());   // 4 applicants x 3 required documents
        $this->assertSame(0, Application::whereHas('student', fn ($q) => $q->where('is_demo', false))->count());
        Storage::disk(Document::DISK)->assertExists(Document::first()->file_path);

        // Running it again only uses demo students who did not apply yet (1 left).
        $this->artisan('demo:applicants', ['count' => 4, '--program' => 'TG'])->assertExitCode(0);
        $this->assertSame(5, Application::count());
    }
}
