<?php

namespace App\Support;

use App\Models\Student;
use App\Models\User;

class DemoData
{
    public static function isDemoEmail(?string $email): bool
    {
        $email = strtolower(trim((string) $email));

        return (bool) preg_match('/^student\d+@carsu\.edu\.ph$/', $email)
            || str_ends_with($email, '@demo.carsu.edu.ph');
    }

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
