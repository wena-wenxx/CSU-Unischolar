<?php

namespace App\Support;

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

    public static function oneSentence(string $what, string $toName): string
    {
        return match (self::mode()) {
            'off' => "{$what} written to the e-mail log only (e-mail is off); {$toName} sees it in their notifications.",
            'test' => "{$what} sent to the test inbox only (not to {$toName}).",
            default => "{$what} sent to {$toName}.",
        };
    }

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
