<?php

namespace App\Jobs;

use App\Mail\ScholarshipInvitationMail;
use App\Models\EmailLog;
use App\Models\Scholarship;
use App\Models\Student;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Mail;

/*
| Sends one AI Recommendations invitation e-mail and records the result in
| email_logs (same rules as SendApplicationEmail: a failure is recorded,
| never shown to the staff member as an error).
*/
class SendInvitationEmail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public function __construct(public int $emailLogId, public string $mode = 'invite', public ?string $note = null)
    {
    }

    public function handle(): void
    {
        $log = EmailLog::find($this->emailLogId);
        $student = $log ? Student::find($log->student_id) : null;
        $scholarship = $log ? Scholarship::find($log->scholarship_id) : null;

        if (!$log || !$student || !$scholarship) {
            return;
        }

        try {
            Mail::to($log->to_email)->send(
                new ScholarshipInvitationMail($student, $scholarship, $this->mode, $log->application_id, $this->note)
            );
            $log->update(['status' => 'sent', 'sent_at' => now(), 'error' => null]);
        } catch (\Throwable $e) {
            $log->update(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 1000)]);
        }
    }
}
