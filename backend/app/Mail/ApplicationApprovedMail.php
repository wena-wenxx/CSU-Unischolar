<?php

namespace App\Mail;

use App\Models\Application;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/*
| "Your scholarship application was approved" e-mail to the student.
| The view is resources/views/emails/application-approved.blade.php.
*/
class ApplicationApprovedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Application $application)
    {
    }

    public static function subjectFor(Application $application): string
    {
        return 'Approved: '.$application->scholarship->name.' | ScholarGuide';
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: self::subjectFor($this->application));
    }

    public function content(): Content
    {
        $student = $this->application->student;

        return new Content(
            view: 'emails.application-approved',
            with: [
                'studentName' => trim("{$student->first_name} {$student->last_name}"),
                'studentId' => $student->student_id,
                'scholarship' => $this->application->scholarship->name,
                'provider' => $this->application->scholarship->provider,
                'link' => rtrim(config('services.frontend.url'), '/').'/student/applications/'.$this->application->id,
            ],
        );
    }
}
