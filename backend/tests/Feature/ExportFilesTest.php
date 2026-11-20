<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\PayrollRecord;
use App\Models\ScholarRecord;
use App\Models\Scholarship;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/*
| Exported files open as real Excel / Word / PDF files and keep IDs and phone numbers exactly
| (leading zeros, dashes). Fictional people only.
*/
class ExportFilesTest extends TestCase
{
    use RefreshDatabase;

    private function student(string $id, string $first, string $last): Student
    {
        $user = User::factory()->create(['role' => 'student']);

        return Student::create(['user_id' => $user->id, 'student_id' => $id, 'first_name' => $first, 'last_name' => $last,
            'middle_name' => 'Peña', 'contact_number' => '09171234567', 'course' => 'BS Information Technology']);
    }

    private function zipEntry(string $bytes, string $entry): string
    {
        $path = tempnam(sys_get_temp_dir(), 'xt');
        file_put_contents($path, $bytes);
        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($path) === true, 'not a valid zip (Office) file');
        $xml = (string) $zip->getFromName($entry);
        $zip->close();
        @unlink($path);

        return $xml;
    }

    public function test_the_forwarded_list_excel_keeps_ids_and_phone_numbers_as_text(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'staff']));
        $program = Scholarship::create(['name' => 'Sample Grant', 'short_name' => 'SG', 'status' => 'active', 'provider' => 'CHED']);
        $app = Application::create(['student_id' => $this->student('2026-00001', 'Juan', 'Navarro')->id,
            'scholarship_id' => $program->id, 'status' => 'complete', 'forwarded_at' => now()]);

        $response = $this->postJson('/api/staff/forwarded/export', ['format' => 'xlsx', 'ids' => [$app->id]])->assertOk();
        $sheet = $this->zipEntry($response->getContent(), 'xl/worksheets/sheet1.xml');

        $this->assertStringContainsString('<t>2026-00001</t>', $sheet);
        $this->assertStringContainsString('<t>09171234567</t>', $sheet);    // leading 0 kept
        $this->assertStringContainsString('Peña', $sheet);                  // ñ kept
        $this->assertMatchesRegularExpression('/forwarded-sg-\d{4}-\d{2}-\d{2}\.xlsx/', $response->headers->get('content-disposition'));
    }

    public function test_the_payroll_form_word_and_pdf_files_have_the_names_and_amounts(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'staff']));
        $program = Scholarship::create(['name' => 'Tertiary Education Subsidy', 'short_name' => 'TES', 'status' => 'active', 'amount' => 20000]);
        $record = ScholarRecord::create(['student_id' => $this->student('221-00101', 'Ana', 'Dela Cruz')->id,
            'scholarship_id' => $program->id, 'status' => 'active', 'currently_enrolled' => true, 'award_amount' => 10000]);
        $entry = PayrollRecord::create(['scholar_record_id' => $record->id, 'amount' => 10000,
            'period' => '1st Semester AY 2026-2027', 'status' => 'draft']);

        $docx = $this->postJson('/api/payroll/form/docx', ['ids' => [$entry->id]])->assertOk()->getContent();
        $body = $this->zipEntry($docx, 'word/document.xml');
        $this->assertStringContainsString('DELA CRUZ', mb_strtoupper(strip_tags($body)));
        $this->assertStringContainsString('10,000.00', strip_tags($body));

        $pdf = $this->postJson('/api/payroll/form/pdf', ['ids' => [$entry->id]])->assertOk()->getContent();
        $this->assertStringStartsWith('%PDF', $pdf);
    }
}
