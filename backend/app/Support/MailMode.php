<?php

namespace App\Support;

/*
|--------------------------------------------------------------------------
| How e-mail is handled right now, in words staff can trust
|--------------------------------------------------------------------------
| Sending official e-mail to real CSU (carsu.edu.ph) accounts needs the
| university's authorization, so during the study e-mail is OFF: messages are
| written to the log (MAIL_MAILER=log) or all go to one test inbox
| (MAIL_ALWAYS_TO). Screens must never say "sent" in those cases; students
| are informed by the in-system notifications either way.
|
|   off     MAIL_MAILER=log / array: nothing leaves the computer
|   test    MAIL_ALWAYS_TO is set: every e-mail goes to that one test inbox
|   live    real sending to the students' own addresses
*/
class MailMode
{
    public static function mode(): string
    {
        $mailer = (string) config('mail.default');
        if (in_array($mailer, ['log', 'array'], true)) {
            return 'off';
        }

        return config('mail.always_to') ? 'test' : 'live';
    }

    /** One sentence for the result message after approvals, e.g. "3 approval e-mails were sent." */
    public static function approvalSentence(int $count): string
    {
        if ($count === 0) {
            return 'No approval e-mail was needed.';
        }
        $mails = $count === 1 ? '1 approval e-mail' : "{$count} approval e-mails";

        return match (self::mode()) {
            'off' => "E-mail is off: {$mails} written to the log only; the students see the update in their notifications.",
            'test' => "{$mails} sent to the test inbox only (not to the students); the students see the update in their notifications.",
            default => "{$mails} sent.",
        };
    }

    /** e.g. "Invitation e-mail sent to Ana Cruz." / "... written to the e-mail log only (e-mail is off)." */
    public static function oneSentence(string $what, string $toName): string
    {
        return match (self::mode()) {
            'off' => "{$what} written to the e-mail log only (e-mail is off); {$toName} sees it in their notifications.",
            'test' => "{$what} sent to the test inbox only (not to {$toName}).",
            default => "{$what} sent to {$toName}.",
        };
    }

    /** For the screens: what happens to e-mail now. */
    public static function describe(): array
    {
        return [
            'mode' => self::mode(),
            'label' => match (self::mode()) {
                'off' => 'E-mail is off (written to the log only). Students are informed by in-system notifications.',
                'test' => 'E-mail goes to one test inbox only, never to students.',
                default => 'E-mail is sent to the students\' own addresses.',
            },
        ];
    }
}
