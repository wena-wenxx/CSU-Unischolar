<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

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
            StatusLogSeeder::class,
            PortalDemoSeeder::class,
        ]);

        \App\Models\Application::fillMissingSchoolYears();
        \App\Models\Application::fillMissingBatches();

        \App\Support\DemoData::markSeededStudents();

        \App\Models\Document::backfillOwners();
        \App\Models\Document::alignSeededDates();

        \App\Models\ScholarRecord::backfillAtmStatus();

        \App\Models\ScholarRecord::fillMissingSchoolYears();

        \App\Models\Application::backfillForwardedAt();

        $this->call(EnrollmentListSeeder::class);

        \App\Models\ActivityLog::backfillFromStatusLogs();

        $this->call(ExternalGranteeSeeder::class);

        \App\Support\EligibilityEngine::recalculate();
    }
}
