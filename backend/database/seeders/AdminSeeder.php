<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Demo system administrator (manages accounts and settings).
 *   admin@carsu.edu.ph / Admin@12345
 * Change this password before any real use.
 */
class AdminSeeder extends Seeder
{
    public function run(): void
    {
        User::updateOrCreate(
            ['email' => 'admin@carsu.edu.ph'],
            [
                'name' => 'System Administrator',
                'password' => Hash::make('Admin@12345'),
                'role' => 'admin',
                'is_active' => true,
            ]
        );
    }
}
