<?php

namespace App\Support;

use App\Jobs\SendApplicationEmail;
use App\Jobs\SendInvitationEmail;
use App\Mail\ApplicationApprovedMail;
use App\Mail\ScholarshipInvitationMail;
use App\Models\Application;
use App\Models\EmailLog;
use App\Models\Scholarship;
use App\Models\Student;

/*
| One place that decides which e-mails go out.
| Called when an application becomes "approved" (staff review or agency list),
| and from AI Recommendations ("Send invitation" / "Add to scholarship").
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

    /*
    | AI Recommendations: "you may be eligible" (mode invite) or "OAS started
    | your application" (mode added, $application = the new draft).
    */
    public static function invitation(Student $student, Scholarship $scholarship, string $mode, ?Application $application = null, ?string $note = null): ?EmailLog
    {
        $student->loadMissing('user');
        $email = $student->user?->email;

        if (!$email) {
            return null;
        }

        $log = EmailLog::create([
            'student_id' => $student->id,
            'application_id' => $application?->id,
            'scholarship_id' => $scholarship->id,
            'type' => 'scholarship_invitation',
            'to_email' => $email,
            'subject' => ScholarshipInvitationMail::subjectFor($scholarship, $mode),
            'trigger' => 'ai_recommendation',
            'status' => 'queued',
        ]);

        try {
            SendInvitationEmail::dispatch($log->id, $mode, $note);
        } catch (\Throwable $e) {
            $log->update(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 1000)]);
        }

        return $log->fresh();
    }
}
