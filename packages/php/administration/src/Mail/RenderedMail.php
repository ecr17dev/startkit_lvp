<?php

namespace Starter\Administration\Mail;

use Illuminate\Notifications\Messages\MailMessage;

/**
 * A template ready to send: subject, HTML and plain text.
 */
final readonly class RenderedMail
{
    public function __construct(
        public string $subject,
        public string $html,
        public string $text,
    ) {}

    /**
     * For notifications (toMail).
     */
    public function toMailMessage(): MailMessage
    {
        return (new MailMessage)
            ->subject($this->subject)
            ->view(['starter-admin::mail.rendered', 'starter-admin::mail.rendered-text'], ['html' => $this->html, 'text' => $this->text]);
    }
}
