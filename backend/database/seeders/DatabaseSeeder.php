<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Runs with: php artisan migrate:fresh --seed
 *
 * ALL DATA IS FICTIONAL DEMO DATA (no real CSU students).
 *
 * Demo logins:
 *   Staff        oas.staff@carsu.edu.ph                         / Staff@12345
 *   Named demo   student1@carsu.edu.ph ... student10@carsu.edu.ph / Student@12345
 *   Bulk demo    s<student id without dash>@demo.carsu.edu.ph   / Student@12345
 *                (e.g. the student 2024-10123 logs in as s202410123@demo.carsu.edu.ph)
 *
 * DemoDataSeeder: 20 scholarship programs + the 10 named demo scenarios.
 * BulkDemoSeeder: 120 more students with a full cycle of applications,
 *                 documents, AI results, grantees and payroll.
 * Run "php artisan storage:link" once so staff can open the sample files.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            StaffSeeder::class,
            StudentSeeder::class,
            DemoDataSeeder::class,
            BulkDemoSeeder::class,
        ]);
    }
}
