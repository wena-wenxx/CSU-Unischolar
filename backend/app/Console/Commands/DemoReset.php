<?php

namespace App\Console\Commands;

use App\Models\AgencyListUpload;
use App\Models\Application;
use App\Models\ProcessingBatch;
use App\Models\Student;
use App\Models\User;
use App\Support\DemoData;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| php artisan demo:reset            demo students again, with no applications
| php artisan demo:reset --full     demo students again, with the full demo history
|--------------------------------------------------------------------------
| For repeating the demonstration. It removes ONLY the seeders' demo students
| (marked is_demo) with their accounts, applications, documents, scholar records
| and payroll entries, then makes the demo accounts again. It never touches staff
| and admin accounts, scholarship programs, settings, the activity log, the real
| test student, or any other student.
|
| Runs only when APP_ENV=local and DEMO_RESET_ALLOWED=true, and asks you to type
| RESET DEMO. Uploaded files stay in storage (they are fictional demo files).
*/
class DemoReset extends Command
{
    protected $signature = 'demo:reset
        {--full : Also rebuild the full demo history (applications, grantees, payroll)}
        {--force : Do not ask for the typed confirmation (automated tests only)}';

    protected $description = 'Remove only the demo students and their records, then make the demo accounts again (local demo only).';

    public function handle(): int
    {
        if (!app()->environment(['local', 'testing'])) {
            $this->error('Refused: demo:reset works only with APP_ENV=local.');

            return self::FAILURE;
        }
        if (!config('demo.reset_allowed')) {
            $this->error('Refused: set DEMO_RESET_ALLOWED=true in .env first (only on a demo computer).');

            return self::FAILURE;
        }

        DemoData::markSeededStudents();
        $demo = Student::where('is_demo', true)->get(['id', 'user_id']);
        $others = Student::where('is_demo', false)->count();

        $this->table(['What', 'Count'], [
            ['Demo students to remove (with their applications, documents, grantee and payroll records)', $demo->count()],
            ['Other students kept (never removed)', $others],
            ['Staff and admin accounts kept', User::whereIn('role', ['staff', 'admin'])->count()],
        ]);
        $this->line('Scholarship programs, settings and the activity log are kept.');

        if (!$this->option('force') && trim((string) $this->ask('Type RESET DEMO to continue')) !== 'RESET DEMO') {
            $this->warn('Nothing was changed.');

            return self::FAILURE;
        }

        $removed = ['students' => 0, 'lists' => 0, 'batches' => 0];

        DB::transaction(function () use ($demo, &$removed) {
            // Deleting the account removes the student and, through the foreign keys, their
            // applications, documents, AI results, scholar records and payroll entries.
            foreach ($demo->pluck('user_id')->filter()->chunk(500) as $chunk) {
                User::whereIn('id', $chunk->all())->where('role', 'student')->delete();
            }
            foreach ($demo->whereNull('user_id')->pluck('id')->chunk(500) as $chunk) {
                Student::whereIn('id', $chunk->all())->delete();
            }
            $removed['students'] = $demo->count();

            // Approved lists whose matched students were all demo students: they matched someone,
            // and no remaining application comes from them. A list that matched nobody is kept.
            $used = Application::whereNotNull('agency_list_upload_id')->distinct()->pluck('agency_list_upload_id');
            $demoLists = AgencyListUpload::whereNotIn('id', $used)->get(['id', 'details'])
                ->filter(fn ($u) => collect($u->details ?? [])->contains(fn ($row) => in_array($row['result'] ?? null, ['approved', 'created', 'already'], true)))
                ->pluck('id');
            $removed['lists'] = $demoLists->isEmpty() ? 0 : AgencyListUpload::whereIn('id', $demoLists->all())->delete();

            // Batches left with nothing in them.
            $removed['batches'] = ProcessingBatch::whereDoesntHave('applications')->whereDoesntHave('uploads')
                ->whereDoesntHave('scholarRecords')->whereDoesntHave('payrollRecords')->delete();
        });

        $this->info("Removed {$removed['students']} demo students, {$removed['lists']} demo approved list(s) and {$removed['batches']} empty batch(es).");

        if ($this->option('full')) {
            $this->call('db:seed', ['--force' => true]);
        } else {
            $this->call('db:seed', ['--class' => 'Database\\Seeders\\StudentSeeder', '--force' => true]);
            DemoData::markSeededStudents();
        }

        $this->info('Done. Demo students now: '.Student::where('is_demo', true)->count()
            .'; applications: '.Application::count().'.');

        return self::SUCCESS;
    }
}
