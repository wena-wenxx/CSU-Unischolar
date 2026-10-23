<?php

namespace App\Mail;

use App\Models\Scholarship;
use App\Models\Student;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/*
| "You may be eligible for ..." e-mail, sent when OAS staff press
| "Send invitation" (mode invite) or "Add to scholarship" (mode added:
| OAS started a draft application for the student) on AI Recommendations.
| It is an invitation only: the student still applies and is reviewed.
| The view is resources/views/emails/scholarship-invitation.blade.php.
*/
class ScholarshipInvitationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Student $student,
        public Scholarship $scholarship,
        public string $mode = 'invite',
        public ?int $applicationId = null,
        public ?string $note = null,
    ) {
    }

    public static function subjectFor(Scholarship $scholarship, string $mode): string
    {
        return ($mode === 'added' ? 'OAS started your application: ' : 'You may be eligible: ')
            .$scholarship->name.' | ScholarGuide';
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: self::subjectFor($this->scholarship, $this->mode));
    }

    public function content(): Content
    {
        $base = rtrim(config('services.frontend.url'), '/');

        return new Content(
            view: 'emails.scholarship-invitation',
            with: [
                'studentName' => $this->student->fullName(),
                'studentId' => $this->student->student_id,
                'programName' => $this->scholarship->name,
                'provider' => $this->scholarship->provider,
                'agencyDirect' => $this->scholarship->isAgencyDirect(),
                'deadline' => $this->scholarship->application_end
                    ? date('F j, Y', strtotime((string) $this->scholarship->application_end)) : null,
                'mode' => $this->mode,
                'note' => $this->note,
                'link' => $this->applicationId
                    ? $base.'/student/applications/'.$this->applicationId
                    : $base.'/student/scholarships/'.$this->scholarship->id,
            ],
        );
    }
}
