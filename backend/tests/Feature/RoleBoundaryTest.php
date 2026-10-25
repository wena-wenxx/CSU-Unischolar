<?php

namespace Tests\Feature;

use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/*
| Permissions are enforced by the SERVER, not only by hiding menu items.
| Every API address is called directly:
|   - logged out             -> 401 (except the 4 public ones)
|   - student on staff/admin -> 403
|   - staff on admin         -> 403
|   - admin on OAS work      -> 403 (the administrator manages accounts and settings only)
*/
class RoleBoundaryTest extends TestCase
{
    use RefreshDatabase;

    private const PUBLIC = ['api/login', 'api/office-contact', 'api/files/documents/{id}'];

    /** Every API route as [method, uri]. */
    private function routes(): array
    {
        return collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($r) => str_starts_with($r->uri(), 'api/'))
            ->map(fn ($r) => [collect($r->methods())->first(fn ($m) => $m !== 'HEAD'), $r->uri()])
            ->values()
            ->all();
    }

    private function hit(string $method, string $uri)
    {
        return $this->json($method, '/'.preg_replace('/\{[^}]+\}/', '1', $uri), []);
    }

    private function user(string $role): User
    {
        $user = User::factory()->create(['role' => $role]);
        if ($role === 'student') {
            Student::create(['user_id' => $user->id, 'student_id' => '2026-88888', 'first_name' => 'Test', 'last_name' => 'Student']);
        }

        return $user;
    }

    public function test_logged_out_visitors_get_401_everywhere_except_the_public_pages(): void
    {
        foreach ($this->routes() as [$method, $uri]) {
            if (in_array($uri, self::PUBLIC, true)) {
                continue;
            }
            $this->assertSame(401, $this->hit($method, $uri)->status(), "$method $uri should need a login");
        }
    }

    public function test_students_cannot_use_staff_or_admin_addresses(): void
    {
        Sanctum::actingAs($this->user('student'));

        foreach ($this->routes() as [$method, $uri]) {
            if (str_starts_with($uri, 'api/staff/') || str_starts_with($uri, 'api/admin/')
                || str_starts_with($uri, 'api/payroll') || str_starts_with($uri, 'api/scholar-records')) {
                $this->assertSame(403, $this->hit($method, $uri)->status(), "student: $method $uri");
            }
        }
    }

    public function test_staff_cannot_use_admin_addresses(): void
    {
        Sanctum::actingAs($this->user('staff'));

        foreach ($this->routes() as [$method, $uri]) {
            if (str_starts_with($uri, 'api/admin/')) {
                $this->assertSame(403, $this->hit($method, $uri)->status(), "staff: $method $uri");
            }
        }
    }

    /*
    | ADMIN_ROLE.md: the admin may READ reports (applications, scholar records,
    | payroll) and edit scholarship programs, but never changes OAS work.
    */
    public function test_the_administrator_can_read_but_not_change_oas_work(): void
    {
        Sanctum::actingAs($this->user('admin'));
        $failures = [];

        foreach ($this->routes() as [$method, $uri]) {
            $oasWork = str_starts_with($uri, 'api/staff/') || str_starts_with($uri, 'api/payroll')
                || str_starts_with($uri, 'api/scholar-records') || str_starts_with($uri, 'api/applications')
                || str_starts_with($uri, 'api/documents/');
            if ($oasWork && $method !== 'GET') {
                $status = $this->hit($method, $uri)->status();
                // 403 = refused; 422/404 = nothing changed (empty form / no record). The document routes are
                // tried again below with a real application, document and complete data.
                if ($status < 300) {
                    $failures[] = "$status $method $uri";
                }
            }
        }

        $this->assertSame([], $failures, 'The admin could change OAS work through: '.implode(', ', $failures));
    }

    public function test_the_administrator_cannot_add_or_check_documents_with_complete_data(): void
    {
        \Illuminate\Support\Facades\Storage::fake('local');
        $student = $this->user('student')->student;
        $program = \App\Models\Scholarship::create(['name' => 'Test Program', 'status' => 'active']);
        $requirement = \App\Models\ScholarshipRequirement::create(['scholarship_id' => $program->id, 'name' => 'Valid ID', 'is_required' => true]);
        $application = \App\Models\Application::create(['student_id' => $student->id, 'scholarship_id' => $program->id, 'status' => 'draft']);
        $document = \App\Models\Document::create(['student_id' => $student->id, 'document_type' => 'Valid ID',
            'original_filename' => 'id.pdf', 'file_path' => 'documents/id.pdf', 'status' => 'uploaded']);
        \Illuminate\Support\Facades\Storage::disk('local')->put('documents/id.pdf', '%PDF-1.4 test');

        Sanctum::actingAs($this->user('admin'));
        $file = \Illuminate\Http\UploadedFile::fake()->create('id.pdf', 10, 'application/pdf');

        $this->post("/api/applications/{$application->id}/documents", ['file' => $file, 'scholarship_requirement_id' => $requirement->id], ['Accept' => 'application/json'])->assertForbidden();
        $this->postJson("/api/applications/{$application->id}/documents/reuse", ['scholarship_requirement_id' => $requirement->id, 'document_id' => $document->id])->assertForbidden();
        $this->postJson("/api/documents/{$document->id}/validate")->assertForbidden();
        $this->assertSame(1, \App\Models\Document::count());   // nothing was added
    }

    public function test_a_student_cannot_reach_another_students_records(): void
    {
        \Illuminate\Support\Facades\Storage::fake('local');
        $other = Student::create(['user_id' => User::factory()->create(['role' => 'student'])->id,
            'student_id' => '2026-99991', 'first_name' => 'Other', 'last_name' => 'Student']);
        $program = \App\Models\Scholarship::create(['name' => 'Test Program', 'status' => 'active']);
        $requirement = \App\Models\ScholarshipRequirement::create(['scholarship_id' => $program->id, 'name' => 'Valid ID', 'is_required' => true]);
        $theirs = \App\Models\Application::create(['student_id' => $other->id, 'scholarship_id' => $program->id, 'status' => 'draft']);
        $theirDoc = \App\Models\Document::create(['student_id' => $other->id, 'document_type' => 'Valid ID',
            'original_filename' => 'id.pdf', 'file_path' => 'documents/id.pdf', 'status' => 'uploaded']);

        Sanctum::actingAs($this->user('student'));
        $file = fn () => \Illuminate\Http\UploadedFile::fake()->create('id.pdf', 10, 'application/pdf');

        $this->getJson("/api/applications/{$theirs->id}")->assertForbidden();
        $this->postJson("/api/applications/{$theirs->id}/submit")->assertForbidden();
        $this->post("/api/applications/{$theirs->id}/documents", ['file' => $file(), 'scholarship_requirement_id' => $requirement->id], ['Accept' => 'application/json'])->assertForbidden();
        $this->post("/api/student/documents/{$theirDoc->id}/replace", ['file' => $file()], ['Accept' => 'application/json'])->assertForbidden();
        $this->postJson("/api/documents/{$theirDoc->id}/validate")->assertForbidden();
        $this->getJson('/api/student/documents')->assertOk()->assertJsonMissing(['original_filename' => 'id.pdf']);
    }

    public function test_a_temporary_password_must_be_changed_before_anything_else(): void
    {
        $staff = $this->user('staff');
        $staff->forceFill(['must_change_password' => true, 'password' => bcrypt('Temp@12345')])->save();
        Sanctum::actingAs($staff);

        $this->getJson('/api/applications')->assertForbidden()->assertJsonFragment(['must_change_password' => true]);
        $this->getJson('/api/user')->assertOk();
        $this->postJson('/api/change-password', ['current_password' => 'Temp@12345', 'password' => 'MyOwn@12345', 'password_confirmation' => 'MyOwn@12345'])->assertOk();
        $this->getJson('/api/applications')->assertOk();
    }

    public function test_nobody_can_sign_themselves_up(): void
    {
        $this->postJson('/api/register', ['first_name' => 'X', 'last_name' => 'Y', 'email' => 'x@test.local',
            'password' => 'Secret@123', 'student_id' => '2026-12345'])->assertNotFound();
        $this->assertSame(0, User::count());
    }

    public function test_sign_in_is_limited_to_10_tries_a_minute(): void
    {
        $this->user('staff')->update(['email' => 'staff@test.local']);

        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/api/login', ['email' => 'staff@test.local', 'password' => 'wrong'])->assertStatus(422);
        }
        $this->postJson('/api/login', ['email' => 'staff@test.local', 'password' => 'wrong'])
            ->assertStatus(429)
            ->assertJsonFragment(['message' => 'Too many sign-in attempts. Wait one minute, then try again.']);
    }
}
