<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Runs with: php artisan migrate:fresh --seed
 *
 * ALL DATA IS FICTIONAL DEMO DATA (no real CSU students).
 *
 * Demo logins:
 *   Admin        admin@carsu.edu.ph                             / Admin@12345
 *   Staff        oas.staff@carsu.edu.ph                         / Staff@12345
 *   Test student wenarose.contiga@carsu.edu.ph (real, with permission) / Student@12345
 *   Named demo   student1@carsu.edu.ph ... student10@carsu.edu.ph / Student@12345
 *   Bulk demo    s<student id without dash>@demo.carsu.edu.ph   / Student@12345
 *                (e.g. the student 2024-10123 logs in as s202410123@demo.carsu.edu.ph)
 *
 * DemoDataSeeder: 20 scholarship programs + the 10 named demo scenarios.
 * BulkDemoSeeder: 120 more students with a full cycle of applications,
 *                 documents, AI results, grantees and payroll.
 * StatusLogSeeder: timeline / notifications / "last updated" for every application.
 * PortalDemoSeeder: OAS announcements and one pending correction request.
 * Run "php artisan storage:link" once so staff can open the sample files.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            AdminSeeder::class,
            StaffSeeder::class,
            StudentSeeder::class,
            DemoDataSeeder::class,
            BulkDemoSeeder::class,
            StatusLogSeeder::class,   // step-by-step history of every application
            PortalDemoSeeder::class,  // announcements + one profile correction request
        ]);

        // Bulk documents were inserted directly; record which student owns each.
        \App\Models\Document::backfillOwners();
        \App\Models\Document::alignSeededDates();

        // Starting ATM status for every grantee (funded / pending / for ATM application).
        \App\Models\ScholarRecord::backfillAtmStatus();

        // When each demo application was forwarded to its agency.
        \App\Models\Application::backfillForwardedAt();

        // A demo Registrar enrollment list for "Verify All Enrollments".
        $this->call(EnrollmentListSeeder::class);

        // Activity log entries for the staff steps already in the demo history.
        \App\Models\ActivityLog::backfillFromStatusLogs();
    }
}
