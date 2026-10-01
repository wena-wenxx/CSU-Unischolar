<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class StudentSeeder extends Seeder
{
    public function run(): void
    {
        User::updateOrCreate(
            [
                'email' => 'oas.student@carsu.edu.ph',
            ],
            [
                'name' => 'OAS Demo Staff',
                'password' => Hash::make('Staff@12345'),
                'role' => 'staff',
            ]
        );
    }
}