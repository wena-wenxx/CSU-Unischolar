<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\ScholarRecord;
use App\Models\Scholarship;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/*
| Round 12 extras: profile photo, Forwarded to Agency export (Excel / PDF),
| and the sample approved lists (php artisan demo:approved-lists).
| Run: php artisan test
*/
class Round12ExtrasTest extends TestCase
{
    use RefreshDatabase;

    // A 120 x 120 green PNG (no GD needed to make it).
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAHgAAAB4CAIAAAC2BqGFAAAAsUlEQVR42u3QQREAAAQAMITRRv86UjifLcIypoN7pUC0aESLFo1o0YgWLRrRohEtWjSiRSNatGhEi0a0aNGIFo1o0aIRLRrRokUjWjSiRYtGtGhEixaNaNGIFi0a0aIRLVo0okUjWrRoRItGtGjRiBaNaNGiES0a0aJFI1o0okWLRrRoRIsWjWjRiBYtGtGiES1aNKJFI1q0aESLRrRo0YgWjWjRohEtGtGiRSNaNKL/LQe4AWM30m5tAAAAAElFTkSuQmCC';

    private function forwarded(int $count, ?Scholarship $program = null): array
    {
        $program ??= Scholarship::create(['name' => 'Test Program', 'short_name' => 'TP', 'status' => 'active', 'provider' => 'Test Agency']);
        $ids = [];
        foreach (range(1, $count) as $i) {
            $user = User::factory()->create(['role' => 'student']);
            $student = Student::create(['user_id' => $user->id, 'student_id' => sprintf('2026-%05d', 80000 + $i + $program->id * 100),
                'first_name' => 'Ana'.$i, 'last_name' => 'Cruz'.$i, 'course' => 'BS Information Technology']);
            $ids[] = Application::create(['student_id' => $student->id, 'scholarship_id' => $program->id, 'status' => 'complete', 'forwarded_at' => now()])->id;
        }

        return $ids;
    }

    public function test_everyone_can_upload_and_remove_their_photo(): void
    {
        Storage::fake('public');
        $user = User::factory()->create(['role' => 'staff']);
        Sanctum::actingAs($user);

        $file = UploadedFile::fake()->createWithContent('me.png', base64_decode(self::PNG));
        $path = $this->post('/api/profile/photo', ['photo' => $file], ['Accept' => 'application/json'])->assertOk()->json('user.photo_path');
        Storage::disk('public')->assertExists($path);

        $this->post('/api/profile/photo', ['photo' => UploadedFile::fake()->createWithContent('a.txt', 'hello')], ['Accept' => 'application/json'])
            ->assertStatus(422);

        $this->deleteJson('/api/profile/photo')->assertOk();
        Storage::disk('public')->assertMissing($path);
        $this->assertNull($user->fresh()->photo_path);
    }

    public function test_forwarded_list_exports_as_excel_and_pdf_for_staff_only(): void
    {
        $ids = $this->forwarded(3);

        Sanctum::actingAs(User::factory()->create(['role' => 'student']));
        $this->postJson('/api/staff/forwarded/export', ['format' => 'xlsx', 'ids' => $ids])->assertStatus(403);

        Sanctum::actingAs(User::factory()->create(['role' => 'staff']));
        $excel = $this->post('/api/staff/forwarded/export', ['format' => 'xlsx', 'ids' => $ids]);
        $excel->assertOk();
        $this->assertStringStartsWith('PK', $excel->getContent());
        $this->assertStringContainsString('forwarded-tp-', $excel->headers->get('Content-Disposition'));

        if (class_exists(\Dompdf\Dompdf::class)) {
            $this->assertStringStartsWith('%PDF', $this->post('/api/staff/forwarded/export', ['format' => 'pdf', 'ids' => $ids])->getContent());
        }
    }

    public function test_sample_approved_lists_use_the_forwarded_students(): void
    {
        $this->forwarded(4);
        $other = Scholarship::create(['name' => 'Other', 'short_name' => 'OT', 'status' => 'active']);
        // One forwarded student already holds another scholarship: the list shows the error case.
        $holder = Application::first();
        ScholarRecord::create(['student_id' => $holder->student_id, 'scholarship_id' => $other->id, 'status' => 'active']);

        $dir = sys_get_temp_dir().'/approved-lists-test';
        $this->artisan('demo:approved-lists', ['--dir' => $dir])->assertSuccessful();

        $csv = File::get($dir.'/approved-tp.csv');
        $this->assertStringContainsString($holder->student->student_id, $csv);   // the error case
        $this->assertStringContainsString('2026-99999', $csv);                    // the unmatched case
        $this->assertSame(2 + 1 + 1 + 1, count(array_filter(explode("\n", trim($csv)))));   // header + 2 free + holder + unknown
        $this->assertFileExists($dir.'/approved-tp.pdf');
        File::deleteDirectory($dir);
    }
}
