<?php

namespace App\Jobs;

use App\Mail\ApplicationApprovedMail;
use App\Models\Application;
use App\Models\EmailLog;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Mail;

/*
| Sends one e-mail and records the result in email_logs.
| QUEUE_CONNECTION=sync  -> sent right away (simplest for the demo).
| QUEUE_CONNECTION=database -> sent by "php artisan queue:work" in the
|                              background, so staff never wait for SMTP.
| A failure is recorded, never shown as an error to the staff member.
*/
class SendApplicationEmail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public function __construct(public int $emailLogId)
    {
    }

    public function handle(): void
    {
        $log = EmailLog::find($this->emailLogId);
        $application = $log ? Application::with(['student', 'scholarship'])->find($log->application_id) : null;

        if (!$log || !$application) {
            return;
        }

        try {
            Mail::to($log->to_email)->send(new ApplicationApprovedMail($application));
            $log->update(['status' => 'sent', 'sent_at' => now(), 'error' => null]);
        } catch (\Throwable $e) {
            $log->update(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 1000)]);
        }
    }
}
