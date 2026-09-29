<?php

namespace Database\Seeders;

use App\Models\Scholarship;
use App\Models\ScholarshipRequirement;
use App\Models\Student;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * SYNTHETIC (fake) data for development/testing only. No real student data.
 * Requirement lists are PLACEHOLDERS - replace with the real OAS lists once confirmed.
 * Safe to run more than once. Run with:
 *   php artisan db:seed --class=DemoDataSeeder
 */
class DemoDataSeeder extends Seeder
{
    public function run(): void
    {
        User::firstOrCreate(
            ['email' => 'staff@csu.edu.ph'],
            ['name' => 'OAS Staff', 'password' => Hash::make('password123'), 'role' => 'staff']
        );

        foreach (['CMSP', 'TDP-TES', 'TES', 'TDP-SUC'] as $name) {
            $scholarship = Scholarship::firstOrCreate(
                ['name' => $name],
                ['provider' => 'CHED', 'description' => 'Demo entry for '.$name.' (synthetic data).', 'status' => 'active']
            );

            foreach (['Certificate of Registration', 'Grades (Report Card)', 'Valid ID'] as $reqName) {
                ScholarshipRequirement::firstOrCreate(
                    ['scholarship_id' => $scholarship->id, 'name' => $reqName],
                    ['is_required' => true]
                );
            }
        }

        $students = [
            ['Maria', 'Santos', 'BSIT'],
            ['Juan', 'Dela Cruz', 'BSIT'],
            ['Ana', 'Reyes', 'BSCS'],
            ['Pedro', 'Garcia', 'BSIS'],
            ['Liza', 'Mendoza', 'BSIT'],
        ];

        foreach ($students as $i => [$first, $last, $course]) {
            $email = strtolower($first).'.'.strtolower(str_replace(' ', '', $last)).'@demo.csu.edu.ph';
            $user = User::firstOrCreate(
                ['email' => $email],
                ['name' => "$first $last", 'password' => Hash::make('password123'), 'role' => 'student']
            );
            Student::firstOrCreate(
                ['user_id' => $user->id],
                ['student_id' => sprintf('2026-%05d', 100 + $i), 'first_name' => $first, 'last_name' => $last,
                    'course' => $course, 'year_level' => '3rd Year', 'college' => 'CCIS']
            );
        }
    }
}
