<?php

namespace Database\Seeders;

use App\Models\Student;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class StudentSeeder extends Seeder
{
    public function run(): void
    {
        // Student 1
        $student1 = User::updateOrCreate(
            [
                'email' => 'student1@carsu.edu.ph',
            ],
            [
                'name' => 'Juan Demo Student',
                'password' => Hash::make('Student@12345'),
                'role' => 'student',
            ]
        );

        Student::updateOrCreate(
            [
                'student_id' => '2026-00001',
            ],
            [
                'user_id' => $student1->id,
                'first_name' => 'Juan',
                'middle_name' => 'Demo',
                'last_name' => 'Student',
                'course' => 'Bachelor of Science in Information Technology',
                'year_level' => '4th Year',
                'college' => 'College of Computing and Information Sciences',
                'contact_number' => '09000000001',
            ]
        );

        // Student 2
        $student2 = User::updateOrCreate(
            [
                'email' => 'student2@carsu.edu.ph',
            ],
            [
                'name' => 'Maria Demo Student',
                'password' => Hash::make('Student@12345'),
                'role' => 'student',
            ]
        );

        Student::updateOrCreate(
            [
                'student_id' => '2026-00002',
            ],
            [
                'user_id' => $student2->id,
                'first_name' => 'Maria',
                'middle_name' => 'Demo',
                'last_name' => 'Student',
                'course' => 'Bachelor of Science in Information Technology',
                'year_level' => '3rd Year',
                'college' => 'College of Computing and Information Sciences',
                'contact_number' => '09000000002',
            ]
        );
    }
}