<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Runs with: php artisan migrate:fresh --seed
 *
 * Demo logins created (all fictional):
 *   Staff        oas.staff@carsu.edu.ph                      / Staff@12345
 *   Students     student1@carsu.edu.ph ... student10@carsu.edu.ph / Student@12345
 *
 * DemoDataSeeder then adds 6 scholarship programs and one application per
 * student in different stages (see the list inside DemoDataSeeder.php).
 * Run "php artisan storage:link" once so staff can open the demo files.
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
