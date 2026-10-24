<?php

namespace App\Actions\Mail;

use App\Models\MailAutomation;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Starter\Administration\Mail\MailTemplates;
use Starter\Administration\Models\MailTemplate;

/**
 * Restore the default of a system template in a language, or delete a
 * custom template in every language (only when no automation uses it).
 */
class DeleteMailTemplate
{
    public function __construct(protected MailTemplates $templates) {}

    public function handle(User $actor, string $key, ?string $locale = null): void
    {
        Gate::forUser($actor)->authorize('mail.manage');

        if ($this->templates->definition($key) !== null) {
            MailTemplate::query()->where('key', $key)->when($locale, fn ($query, string $locale) => $query->where('locale', $locale))->delete();

            audit('mail.template_reset', null, ['key' => $key, 'locale' => $locale], $actor);

            return;
        }

        if (MailAutomation::query()->where('template_key', $key)->exists()) {
            throw ValidationException::withMessages(['template' => __('An automation uses this template: change it first.')]);
        }

        MailTemplate::query()->where('key', $key)->delete();

        audit('mail.template_deleted', null, ['key' => $key], $actor);
    }
}
