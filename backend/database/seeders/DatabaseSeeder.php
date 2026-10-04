<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Runs with: php artisan migrate:fresh --seed
 *
 * Demo logins created:
 *   Staff     oas.staff@carsu.edu.ph  / Staff@12345
 *   Student 1 student1@carsu.edu.ph   / Student@12345
 *   Student 2 student2@carsu.edu.ph   / Student@12345
 *   Student 3 student3@carsu.edu.ph   / Student@12345
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            StaffSeeder::class,
            StudentSeeder::class,
            DemoDataSeeder::class,
        ]);
    }
}
