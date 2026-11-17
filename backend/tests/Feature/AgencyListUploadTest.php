<?php

namespace Tests\Feature;

use App\Models\Scholarship;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AgencyListUploadTest extends TestCase
{
    use RefreshDatabase;

    public function test_period_details_are_saved_with_an_agency_list(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'staff']));
        $scholarship = Scholarship::create(['name' => 'TES', 'provider' => 'CHED']);

        $response = $this->postJson('/api/staff/agency-lists', [
            'scholarship_id' => $scholarship->id,
            'agency_name' => 'CHED',
            'file_name' => 'tes.csv',
            'school_year' => '2026-2027',
            'semester' => '1st Semester',
            'batch' => 'Batch 2',
            'reference_number' => 'CARAGA-CSU-MAIN-2026-1-1-B2',
            'list_date' => '2026-11-03',
            'expected_count' => 1,
            'expected_total' => 10000,
            'confirmed_mismatch' => true,
            'rows' => [[
                'row' => 2,
                'student_id' => '2026-99999',
                'amount' => 10000,
            ]],
        ]);

        $response->assertCreated()->assertJsonPath('upload.school_year', '2026-2027');
        $this->assertDatabaseHas('agency_list_uploads', [
            'reference_number' => 'CARAGA-CSU-MAIN-2026-1-1-B2',
            'batch' => 'Batch 2',
            'semester' => '1st Semester',
        ]);
    }

    public function test_invalid_semester_is_rejected(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'staff']));
        $scholarship = Scholarship::create(['name' => 'TES', 'provider' => 'CHED']);

        $this->postJson('/api/staff/agency-lists', [
            'scholarship_id' => $scholarship->id,
            'agency_name' => 'CHED',
            'file_name' => 'tes.csv',
            'semester' => 'Autumn',
            'rows' => [['row' => 2, 'student_id' => '2026-99999']],
        ])->assertStatus(422)->assertJsonValidationErrors(['semester']);
    }
}
