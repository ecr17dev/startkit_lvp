<?php

namespace Starter\Administration\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Content already rendered (editor previews sent to oneself).
 */
class RenderedMailable extends Mailable
{
    public function __construct(public RenderedMail $mail) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->mail->subject);
    }

    public function content(): Content
    {
        return new Content(
            view: 'starter-admin::mail.rendered',
            text: 'starter-admin::mail.rendered-text',
            with: ['html' => $this->mail->html, 'text' => $this->mail->text],
        );
    }
}
