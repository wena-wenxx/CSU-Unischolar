<?php

namespace Tests\Feature;

use App\Models\EmailLog;
use Tests\TestCase;

/* The e-mail error shown to staff says what to fix in backend/.env. */
class EmailErrorTest extends TestCase
{
    public function test_common_gmail_errors_are_explained(): void
    {
        $this->assertStringContainsString('App Password', EmailLog::plainError('Expected response code "235" but got code "535", with message "535-5.7.8 Username and Password not accepted."'));
        $this->assertStringContainsString('MAIL_SCHEME', EmailLog::plainError('The "tls" scheme is not supported; supported schemes for mailer "smtp" are: "smtp", "smtps".'));
        $this->assertStringContainsString('could not be reached', EmailLog::plainError('Connection could not be established with host "smtp.gmail.com:587"'));
        $this->assertStringStartsWith('Reason: ', EmailLog::plainError('Something else went wrong'));
    }
}
