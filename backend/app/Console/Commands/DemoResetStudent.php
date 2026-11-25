<?php

namespace App\Console\Commands;

use App\Models\AiRecommendation;
use App\Models\Application;
use App\Models\Document;
use App\Models\ScholarRecord;
use App\Models\ScholarshipNotification;
use App\Models\Student;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class DemoResetStudent extends Command
{
    protected $signature = 'demo:reset-student
        {who? : E-mail or Student ID (default: TEST_STUDENT_EMAIL)}
        {--documents : Also remove the files in the student\'s My Documents}
        {--force : Do not ask for the typed confirmation (automated tests only)}';

    protected $description = 'Put the test student (or one demo student) back to "not applied yet" to try the process again.';

    public function handle(): int
    {
        if (!app()->environment(['local', 'testing']) || !config('demo.reset_allowed')) {
            $this->error('Refused: works only with APP_ENV=local and DEMO_RESET_ALLOWED=true (demo computer).');

            return self::FAILURE;
        }

        $who = trim((string) ($this->argument('who') ?: config('demo.test_student.email')));
        $student = Student::with('user')
            ->where('student_id', $who)
            ->orWhereHas('user', fn ($u) => $u->where('email', $who))
            ->first();

        if (!$student) {
            $this->error("No student found for \"{$who}\".");

            return self::FAILURE;
        }

        $isTest = $student->user && strcasecmp((string) $student->user->email, (string) config('demo.test_student.email')) === 0;
        if (!$student->is_demo && !$isTest) {
            $this->error('Refused: only the test student (TEST_STUDENT_EMAIL) or a demo student can be reset.');

            return self::FAILURE;
        }

        $apps = Application::where('student_id', $student->id)->get(['id', 'status']);
        $appDocs = Document::whereIn('application_id', $apps->pluck('id'))->get();
        $libraryDocs = $this->option('documents')
            ? Document::where('student_id', $student->id)->whereNull('application_id')->get()
            : collect();

        $this->info("{$student->first_name} {$student->last_name} ({$student->student_id})");
        $this->table(['Will be removed', 'Count'], [
            ['Applications (all statuses)', $apps->count()],
            ['Documents attached to those applications', $appDocs->count()],
            ['Grantee records (and their payroll entries)', ScholarRecord::where('student_id', $student->id)->count()],
            ['Invitations / notices', ScholarshipNotification::where('student_id', $student->id)->count()],
            ['My Documents files', $this->option('documents') ? $libraryDocs->count() : 'kept'],
        ]);
        $this->line('Kept: the account and password, profile, photo, messages and the activity log.');

        if (!$this->option('force') && trim((string) $this->ask('Type RESET to continue')) !== 'RESET') {
            $this->warn('Nothing was changed.');

            return self::FAILURE;
        }

        $files = $appDocs->merge($libraryDocs)->pluck('file_path')->filter()->all();

        DB::transaction(function () use ($student, $apps, $libraryDocs) {
            ScholarRecord::where('student_id', $student->id)->delete();
            Application::whereIn('id', $apps->pluck('id'))->delete();
            Document::whereIn('id', $libraryDocs->pluck('id'))->delete();
            ScholarshipNotification::where('student_id', $student->id)->delete();
            AiRecommendation::where('student_id', $student->id)
                ->update(['staff_status' => 'new', 'invited_at' => null]);
        });

        $stillUsed = Document::whereIn('file_path', $files)->pluck('file_path')->all();
        foreach (array_diff($files, $stillUsed) as $path) {
            Storage::disk(Document::DISK)->delete($path);
        }

        \App\Models\ActivityLog::record(null, 'demo.student_reset',
            "Demo reset of {$student->first_name} {$student->last_name} ({$student->student_id}): {$apps->count()} application(s) removed.");

        $this->info('Done. The student can apply again. Log in as '.($student->user?->email ?? 'the student').' (password unchanged).');

        return self::SUCCESS;
    }
}
