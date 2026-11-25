<?php

namespace Database\Seeders;

use App\Models\Student;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class StudentSeeder extends Seeder
{
    public const MIDDLE = [
        '2026-00001' => 'Lopez', '2026-00002' => 'Cruz', '2026-00003' => 'Torres', '2026-00004' => 'Aquino',
        '2026-00005' => 'Morales', '2026-00006' => 'Bautista', '2026-00007' => 'Castillo', '2026-00008' => 'Domingo',
        '2026-00009' => 'Fernandez', '2026-00010' => 'Tan',
    ];

    public const STUDENTS = [
        [1, '2026-00001', 'Juan', 'Navarro', 'Bachelor of Science in Information Technology', '4th Year', 'College of Computing and Information Sciences'],
        [2, '2026-00002', 'Maria', 'Santos', 'Bachelor of Science in Information Technology', '3rd Year', 'College of Computing and Information Sciences'],
        [3, '2026-00003', 'Ana', 'Ramirez', 'Bachelor of Science in Computer Science', '2nd Year', 'College of Computing and Information Sciences'],
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
        $staffId = User::where('role', 'staff')->value('id');

        $real = config('demo.test_student');
        $tester = User::updateOrCreate(
            ['email' => $real['email']],
            [
                'name' => trim("{$real['first_name']} {$real['middle_name']} {$real['last_name']}"),
                'password' => Hash::make('Student@12345'),
                'role' => 'student',
            ]
        );

        Student::updateOrCreate(
            ['student_id' => $real['student_id']],
            [
                'user_id' => $tester->id,
                'first_name' => $real['first_name'],
                'middle_name' => $real['middle_name'],
                'last_name' => $real['last_name'],
                'sex' => $real['sex'],
                'course' => $real['course'],
                'year_level' => $real['year_level'],
                'college' => $real['college'],
                'contact_number' => null,
            ]
        );

        if ($real['photo'] && is_file($real['photo'])) {
            $path = 'avatars/test-student.'.strtolower(pathinfo($real['photo'], PATHINFO_EXTENSION) ?: 'jpg');
            \Illuminate\Support\Facades\Storage::disk('public')->put($path, file_get_contents($real['photo']));
            $tester->update(['photo_path' => $path]);
        }

        foreach (self::STUDENTS as [$number, $studentId, $first, $last, $course, $yearLevel, $college]) {

            $user = User::updateOrCreate(
                ['email' => "student{$number}@carsu.edu.ph"],
                [
                    'name' => "{$first} ".self::MIDDLE[$studentId]." {$last}",
                    'password' => Hash::make('Student@12345'),
                    'role' => 'student',
                ]
            );

            Student::updateOrCreate(
                ['student_id' => $studentId],
                [
                    'user_id' => $user->id,
                    'first_name' => $first,
                    'middle_name' => self::MIDDLE[$studentId],
                    'last_name' => $last,
                    'sex' => in_array($first, ['Maria', 'Ana', 'Liza', 'Rosa', 'Grace'], true) ? 'Female' : 'Male',
                    'course' => $course,
                    'year_level' => $yearLevel,
                    'college' => $college,
                    'contact_number' => sprintf('090000000%02d', $number),
                    'gwa' => 1.47,
                    'family_income' => 96000,
                    'eligibility_updated_at' => now()->subDays(30),
                    'eligibility_verified_at' => now()->subDays(20),
                    'eligibility_verified_by' => $staffId,
                ]
            );
        }
    }
}
