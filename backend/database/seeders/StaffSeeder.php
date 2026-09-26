<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class StaffSeeder extends Seeder
{
    public function run(): void
    {
        User::create([
            'name' => 'OAS Staff',
            'email' => 'staff@csu.edu.ph',
            'password' => Hash::make('password123'),
            'role' => 'staff',
        ]);
    }
}