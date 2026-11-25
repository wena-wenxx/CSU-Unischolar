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
