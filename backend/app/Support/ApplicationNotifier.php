<?php

namespace App\Support;

use App\Jobs\SendApplicationEmail;
use App\Mail\ApplicationApprovedMail;
use App\Models\Application;
use App\Models\EmailLog;

/*
| One place that decides which e-mails go out.
| Called when an application becomes "approved" (staff review or agency list).
*/
class ApplicationNotifier
{
    public static function approved(Application $application, string $trigger): ?EmailLog
    {
        $application->loadMissing(['student.user', 'scholarship']);
        $email = $application->student?->user?->email;

        if (!$email) {
            return null;
        }

        $log = EmailLog::create([
            'student_id' => $application->student_id,
            'application_id' => $application->id,
            'type' => 'application_approved',
            'to_email' => $email,
            'subject' => ApplicationApprovedMail::subjectFor($application),
            'trigger' => $trigger,
            'status' => 'queued',
        ]);

        try {
            SendApplicationEmail::dispatch($log->id);
        } catch (\Throwable $e) {
            $log->update(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 1000)]);
        }

        return $log->fresh();
    }
}
