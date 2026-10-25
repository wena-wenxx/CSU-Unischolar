<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmailLog extends Model
{
    protected $fillable = [
        'student_id', 'application_id', 'scholarship_id', 'type', 'to_email', 'subject', 'trigger', 'status', 'error', 'sent_at',
    ];

    protected $casts = [
        'sent_at' => 'datetime',
    ];

    /**
     * The sending error in plain words, with what to change in backend/.env
     * (then run "php artisan config:clear" and restart "php artisan serve").
     */
    public static function plainError(?string $error): string
    {
        $e = (string) $error;
        $has = fn (string ...$needles) => collect($needles)->contains(fn ($n) => stripos($e, $n) !== false);

        return match (true) {
            $e === '' => 'No e-mail address or no reason recorded.',
            $has('scheme is not supported') => 'MAIL_SCHEME is set to an old value (tls/ssl). Set MAIL_SCHEME=null (port 587) or MAIL_SCHEME=smtps (port 465).',
            $has('535', 'Username and Password not accepted', 'BadCredentials') => 'Gmail did not accept MAIL_USERNAME / MAIL_PASSWORD. Use the 16-letter App Password (no spaces), not your normal Gmail password.',
            $has('530', 'Authentication Required', 'must issue a STARTTLS') => 'Gmail needs a login: fill in MAIL_USERNAME and MAIL_PASSWORD.',
            $has('553', '550', 'sender address', 'not owned by user') => 'MAIL_FROM_ADDRESS must be the same Gmail address as MAIL_USERNAME.',
            $has('Connection could not be established', 'getaddrinfo', 'timed out', 'unreachable', 'Connection refused') => 'The mail server could not be reached. Check the internet connection, MAIL_HOST=smtp.gmail.com and MAIL_PORT=587.',
            $has('certificate', 'SSL operation failed') => 'A security certificate problem with the mail server. Use MAIL_PORT=587 with MAIL_SCHEME=null.',
            default => 'Reason: '.mb_substr(trim(preg_replace('/\s+/', ' ', $e)), 0, 160),
        };
    }

    public function student() { return $this->belongsTo(Student::class); }
    public function application() { return $this->belongsTo(Application::class); }
    public function scholarship() { return $this->belongsTo(Scholarship::class); }
}
