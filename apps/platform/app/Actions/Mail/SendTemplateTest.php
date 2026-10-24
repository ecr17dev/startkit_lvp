<?php

namespace App\Actions\Mail;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Starter\Administration\Mail\MailTemplates;
use Starter\Administration\Mail\RenderedMailable;

/**
 * Send the content of the editor (saved or not) with the preview values to
 * the person editing it, through the configured mail provider.
 */
class SendTemplateTest
{
    public function __construct(protected MailTemplates $templates) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public function handle(User $actor, string $key, array $input): void
    {
        Gate::forUser($actor)->authorize('mail.manage');

        $data = Validator::make($input, SaveMailTemplate::rules($key))->validate();

        $rendered = $this->templates->preview($key, $data['subject'], $data['format'] === 'html' ? SaveMailTemplate::cleanHtml($data['body']) : $data['body'], $data['format'], [
            'recipient_name' => $actor->name,
            'recipient_email' => $actor->email,
        ]);

        Mail::to($actor->email)->send(new RenderedMailable($rendered));

        audit('mail.template_tested', null, ['key' => $key], $actor);
    }
}
