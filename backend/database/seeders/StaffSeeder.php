<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class StaffSeeder extends Seeder
{
    public function run(): void
    {
        User::updateOrCreate(
            [
                'email' => 'oas.staff@carsu.edu.ph',
            ],
            [
                'name' => 'Andrea Mae Villareal',   // fictional OAS staff account
                'password' => Hash::make('Staff@12345'),
                'role' => 'staff',
            ]
        );
    }
}