<?php

namespace Database\Seeders;

use App\Models\Student;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Ten FICTIONAL demo students (no real CSU student data).
 * Every account uses the password Student@12345.
 * "Demo" as the middle name makes it obvious these are not real people.
 */
class StudentSeeder extends Seeder
{
    public const STUDENTS = [
        // email number, student ID, first, last, course, year level, college
        [1, '2026-00001', 'Juan', 'Student', 'Bachelor of Science in Information Technology', '4th Year', 'College of Computing and Information Sciences'],
        [2, '2026-00002', 'Maria', 'Student', 'Bachelor of Science in Information Technology', '3rd Year', 'College of Computing and Information Sciences'],
        [3, '2026-00003', 'Ana', 'Student', 'Bachelor of Science in Computer Science', '2nd Year', 'College of Computing and Information Sciences'],
        [4, '2026-00004', 'Pedro', 'Garcia', 'Bachelor of Science in Civil Engineering', '3rd Year', 'College of Engineering'],
        [5, '2026-00005', 'Liza', 'Mendoza', 'Bachelor of Science in Biology', '1st Year', 'College of Mathematics and Natural Sciences'],
        [6, '2026-00006', 'Carlo', 'Reyes', 'Bachelor of Science in Agriculture', '2nd Year', 'College of Agriculture'],
        [7, '2026-00007', 'Rosa', 'Villanueva', 'Bachelor of Science in Accountancy', '3rd Year', 'College of Business'],
        [8, '2026-00008', 'Mark', 'Dela Cruz', 'Bachelor of Science in Mathematics', '4th Year', 'College of Mathematics and Natural Sciences'],
        [9, '2026-00009', 'Jose', 'Ramos', 'Bachelor of Science in Information Systems', '3rd Year', 'College of Computing and Information Sciences'],
        [10, '2026-00010', 'Grace', 'Lim', 'Bachelor of Science in Chemistry', '2nd Year', 'College of Mathematics and Natural Sciences'],
    ];

    public function run(): void
    {
        foreach (self::STUDENTS as [$number, $studentId, $first, $last, $course, $yearLevel, $college]) {

            $user = User::updateOrCreate(
                ['email' => "student{$number}@carsu.edu.ph"],
                [
                    'name' => "{$first} Demo {$last}",
                    'password' => Hash::make('Student@12345'),
                    'role' => 'student',
                ]
            );

            Student::updateOrCreate(
                ['student_id' => $studentId],
                [
                    'user_id' => $user->id,
                    'first_name' => $first,
                    'middle_name' => 'Demo',
                    'last_name' => $last,
                    'course' => $course,
                    'year_level' => $yearLevel,
                    'college' => $college,
                    'contact_number' => sprintf('090000000%02d', $number),
                ]
            );
        }
    }
}
