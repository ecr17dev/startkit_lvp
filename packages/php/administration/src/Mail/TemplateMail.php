<?php

namespace Starter\Administration\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Any template as a mailable, rendered when it is sent (in the locale of
 * the mailable when one is set).
 */
class TemplateMail extends Mailable
{
    use Queueable, SerializesModels;

    protected ?RenderedMail $rendered = null;

    /**
     * @param  array<string, scalar|null>  $variables
     */
    public function __construct(
        public string $templateKey,
        public array $variables = [],
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->rendered()->subject);
    }

    public function content(): Content
    {
        $rendered = $this->rendered();

        return new Content(
            view: 'starter-admin::mail.rendered',
            text: 'starter-admin::mail.rendered-text',
            with: ['html' => $rendered->html, 'text' => $rendered->text],
        );
    }

    public function rendered(): RenderedMail
    {
        return $this->rendered ??= app(MailTemplates::class)->render($this->templateKey, $this->variables);
    }

    /**
     * @return list<string>
     */
    public function __sleep(): array
    {
        return array_values(array_diff(array_keys(get_object_vars($this)), ['rendered']));
    }
}
