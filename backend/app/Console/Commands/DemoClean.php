<?php

namespace App\Console\Commands;

use App\Models\Document;
use App\Models\ExternalGranteeUpload;
use App\Models\Student;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/*
| php artisan demo:clean
|
| "Start clean" on a DEMO computer: removes every record and every upload history, in all roles, so the
| whole process can be tested from zero:
|   removed  applications, documents (and the files), AI results, status history, approved-list uploads,
|            agency submissions, Registrar/enrollment lists, external grantee lists, grantee records,
|            payroll, batches, invitations/notices, AI recommendations, messages, profile change
|            requests, e-mail log and the activity log (AI Recommendations are scored again for everyone)
|   kept     every account (admin, staff, the test student, demo students) with password and profile,
|            scholarship programs and their requirements, settings, announcements
| Refused when the database has a real student (not demo, not the test student): real data is never
| removed by a demo command. Works only with APP_ENV=local and DEMO_RESET_ALLOWED=true, after typing
| CLEAN ALL. To get the sample history back afterwards: php artisan demo:reset --full
*/
class DemoClean extends Command
{
    protected $signature = 'demo:clean
        {--force : Do not ask for the typed confirmation (automated tests only)}';

    protected $description = 'Demo computer only: remove all applications, uploads and history (accounts and programs are kept).';

    /** Children first, so foreign keys never block a delete. */
    private const TABLES = [
        'payroll_records' => 'Payroll entries',
        'scholar_records' => 'Grantee records',
        'validation_results' => 'AI check results',
        'documents' => 'Documents (uploaded files)',
        'application_status_logs' => 'Application status history',
        'applications' => 'Applications',
        'agency_submissions' => 'Agency sendings (forwarded)',
        'agency_list_uploads' => 'Approved list upload history',
        'enrollment_list_entries' => 'Registrar list rows',
        'enrollment_lists' => 'Registrar list upload history',
        'external_grantees' => 'External grantees',
        'external_grantee_uploads' => 'External grantee upload history',
        'processing_batches' => 'Batches',
        'scholarship_notifications' => 'Invitations / notices',
        'ai_recommendations' => 'AI recommendations',
        'recommendation_logs' => 'AI recommendation history',
        'contact_messages' => 'Student messages',
        'profile_change_requests' => 'Profile change requests',
        'email_logs' => 'E-mails sent (log)',
        'activity_logs' => 'Activity log',
        'jobs' => 'Waiting background jobs',
        'failed_jobs' => 'Failed background jobs',
    ];

    public function handle(): int
    {
        if (!app()->environment(['local', 'testing']) || !config('demo.reset_allowed')) {
            $this->error('Refused: works only with APP_ENV=local and DEMO_RESET_ALLOWED=true (demo computer).');

            return self::FAILURE;
        }

        $testEmail = (string) config('demo.test_student.email');
        $real = Student::where('is_demo', false)
            ->where(fn ($q) => $q->whereNull('user_id')
                ->orWhereHas('user', fn ($u) => $u->whereRaw('LOWER(email) <> ?', [strtolower($testEmail)])))
            ->count();
        if ($real > 0) {
            $this->error("Refused: {$real} real student(s) are in this database. demo:clean is only for a demo database.");

            return self::FAILURE;
        }

        $rows = [];
        foreach (self::TABLES as $table => $label) {
            if (Schema::hasTable($table)) {
                $rows[] = [$label, DB::table($table)->count()];
            }
        }
        $this->table(['Will be removed', 'Count'], $rows);
        $this->line('Kept: all accounts ('.User::count().') with passwords and profiles, scholarship programs, requirements, settings and announcements.');

        if (!$this->option('force') && trim((string) $this->ask('Type CLEAN ALL to continue')) !== 'CLEAN ALL') {
            $this->warn('Nothing was changed.');

            return self::FAILURE;
        }

        $files = Document::pluck('file_path')->filter()->unique()->all();
        $listFiles = ExternalGranteeUpload::pluck('file_path')->filter()->all();

        DB::transaction(function () {
            foreach (array_keys(self::TABLES) as $table) {
                if (Schema::hasTable($table)) {
                    DB::table($table)->delete();
                }
            }
        });

        foreach ($files as $path) {
            Storage::disk(Document::DISK)->delete($path);
        }
        foreach ($listFiles as $path) {
            Storage::disk('local')->delete($path);
        }

        // AI Recommendations are worked out from the students' profiles (not history): score everyone again
        // right away, so the page lists every student and not only the first one whose profile changes.
        $scored = \App\Support\EligibilityEngine::recalculate();
        $this->line("AI Recommendations scored again: {$scored['students']} students x {$scored['scholarships']} programs.");

        \App\Models\ActivityLog::record(null, 'demo.clean', 'Demo database cleaned: all applications, uploads and history removed.');

        $this->info('Done. Everything starts from zero. Accounts and passwords are unchanged.');
        $this->line('Sample history back: php artisan demo:reset --full');

        return self::SUCCESS;
    }
}
