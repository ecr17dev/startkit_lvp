<?php

namespace App\Mail;

use Starter\Administration\Mail\TemplateMail;

/**
 * Test email of the mail settings (template mail.test).
 */
class TestMail extends TemplateMail
{
    public function __construct(string $mailer = '')
    {
        parent::__construct('mail.test', ['mailer' => $mailer ?: (string) config('mail.default')]);
    }
}
