<?php

namespace Database\Seeders;

use App\Models\Scholarship;
use App\Models\ScholarshipRequirement;
use Illuminate\Database\Seeder;

/**
 * Demo scholarship programs and their document requirements.
 *
 * Synthetic data for development and the capstone demo only.
 * Amounts and application dates are left empty on purpose: fill them in
 * with OAS's official figures from the Scholarships page (staff login).
 * The requirement lists are typical for each program; confirm them with
 * OAS before the final defense.
 *
 * Safe to run more than once (it never creates duplicates):
 *   php artisan db:seed --class=DemoDataSeeder
 */
class DemoDataSeeder extends Seeder
{
    // Requirement names. Keep these EXACT: the AI service uses them to
    // check whether an uploaded file is the right kind of document.
    private const COR = 'Certificate of Registration (COR)';
    private const GRADES = 'Certificate of Grades';
    private const INDIGENCY = 'Certificate of Indigency';
    private const BIRTH = 'Birth Certificate';
    private const VALID_ID = 'Valid ID';
    private const BARANGAY = 'Barangay Clearance';

    public function run(): void
    {
        $programs = [
            [
                'name' => 'CSU Student Assistance Scholarship',
                'provider' => 'Caraga State University',
                'description' => 'University-funded assistance for financially challenged students in good academic standing.',
                'requirements' => [self::COR, self::GRADES, self::INDIGENCY, self::VALID_ID],
            ],
            [
                'name' => 'CHED Merit Scholarship Program (CMSP)',
                'provider' => 'Commission on Higher Education (CHED)',
                'description' => 'Merit-based scholarship for students with high academic performance from qualified low-income households.',
                'requirements' => [self::COR, self::GRADES, self::BIRTH, self::INDIGENCY, self::VALID_ID],
            ],
            [
                'name' => 'Tertiary Education Subsidy (TES)',
                'provider' => 'CHED - UniFAST',
                'description' => 'Grant-in-aid for financially disadvantaged students enrolled in public higher education institutions.',
                'requirements' => [self::COR, self::BIRTH, self::INDIGENCY, self::VALID_ID],
            ],
            [
                'name' => 'DOST-SEI Scholarship',
                'provider' => 'Department of Science and Technology - Science Education Institute',
                'description' => 'Scholarship for students taking priority science, technology, engineering and mathematics courses.',
                'requirements' => [self::COR, self::GRADES, self::BIRTH, self::VALID_ID],
            ],
            [
                'name' => 'CSU Cultural Grant (Choir / Dance Troupe / Kayam Ethno Band)',
                'provider' => 'Caraga State University',
                'description' => 'Grant for active members of the CSU Choir, Dance Troupe or Kayam Ethno Band.',
                'requirements' => [self::COR, self::GRADES, self::VALID_ID],
            ],
            [
                'name' => 'LGU Butuan City Scholarship',
                'provider' => 'City Government of Butuan',
                'description' => 'Scholarship for qualified bona fide residents of Butuan City.',
                'requirements' => [self::COR, self::GRADES, self::BARANGAY, self::INDIGENCY, self::VALID_ID],
            ],
        ];

        foreach ($programs as $program) {

            $scholarship = Scholarship::firstOrCreate(
                ['name' => $program['name']],
                [
                    'provider' => $program['provider'],
                    'description' => $program['description'],
                    'status' => 'active',
                ]
            );

            foreach ($program['requirements'] as $requirementName) {
                ScholarshipRequirement::firstOrCreate(
                    [
                        'scholarship_id' => $scholarship->id,
                        'name' => $requirementName,
                    ],
                    [
                        'is_required' => true,
                    ]
                );
            }
        }
    }
}
