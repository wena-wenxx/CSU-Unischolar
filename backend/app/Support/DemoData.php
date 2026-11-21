<?php

namespace App\Support;

use App\Models\Student;
use App\Models\User;

/*
|--------------------------------------------------------------------------
| Which students are demo accounts
|--------------------------------------------------------------------------
| The seeders make fictional students with these e-mail addresses:
|   student1 ... student10@carsu.edu.ph      (named demo scenarios)
|   s<student id>@demo.carsu.edu.ph           (bulk demo students)
| Only students marked is_demo can be removed by "php artisan demo:reset".
| The real test student (TEST_STUDENT_EMAIL) and every other student are never
| marked, so they are never removed.
*/
class DemoData
{
    public static function isDemoEmail(?string $email): bool
    {
        $email = strtolower(trim((string) $email));

        return (bool) preg_match('/^student\d+@carsu\.edu\.ph$/', $email)
            || str_ends_with($email, '@demo.carsu.edu.ph');
    }

    /** Marks the seeders' demo students; returns how many are marked now. */
    public static function markSeededStudents(): int
    {
        $userIds = User::where('role', 'student')->get(['id', 'email'])
            ->filter(fn (User $u) => self::isDemoEmail($u->email))
            ->pluck('id');

        foreach ($userIds->chunk(500) as $chunk) {
            Student::whereIn('user_id', $chunk->all())->where('is_demo', false)->update(['is_demo' => true]);
        }

        return Student::where('is_demo', true)->count();
    }
}
