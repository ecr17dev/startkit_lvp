<?php

namespace App\Actions\Mail;

use App\Models\User;
use Closure;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Starter\Administration\Mail\MailTemplates;
use Starter\Administration\Models\MailTemplate;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Save a template in one language: customizes a system template or
 * updates a custom one. Unknown variables are rejected and HTML bodies lose
 * scripts, frames and event handlers.
 */
class SaveMailTemplate
{
    public function __construct(protected MailTemplates $templates) {}

    /**
     * @return array<string, mixed>
     */
    public static function rules(string $key = ''): array
    {
        $unknown = function (string $attribute, mixed $value, Closure $fail) use ($key) {
            $variables = is_string($value) ? app(MailTemplates::class)->unknownVariables($key, $value) : [];

            if ($variables !== []) {
                $fail(__('Unknown variables: :variables.', ['variables' => implode(', ', array_map(fn ($name) => '{{ '.$name.' }}', $variables))]));
            }
        };

        return [
            'locale' => ['required', Rule::in(array_keys((array) config('starter.locales')))],
            'name' => [str_starts_with($key, 'custom.') ? 'required' : 'nullable', 'string', 'max:120'],
            'subject' => ['required', 'string', 'max:255', $unknown],
            'body' => ['required', 'string', 'max:100000', $unknown],
            'format' => ['required', Rule::in(MailTemplates::FORMATS)],
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function handle(User $actor, string $key, array $input): MailTemplate
    {
        Gate::forUser($actor)->authorize('mail.manage');

        if ($this->templates->definition($key) === null && ! MailTemplate::query()->where('key', $key)->exists()) {
            throw new NotFoundHttpException;
        }

        $data = Validator::make($input, self::rules($key))->validate();

        $template = MailTemplate::query()->updateOrCreate(
            ['key' => $key, 'locale' => $data['locale']],
            [
                'name' => $data['name'] ?? MailTemplate::query()->where('key', $key)->value('name'),
                'subject' => $data['subject'],
                'body' => $data['format'] === 'html' ? self::cleanHtml($data['body']) : $data['body'],
                'format' => $data['format'],
                'updated_by' => $actor->id,
            ],
        );

        audit('mail.template_saved', $template, ['key' => $key, 'locale' => $template->locale, 'format' => $template->format], $actor);

        return $template;
    }

    /**
     * Emails never need scripts, frames or event handlers.
     */
    public static function cleanHtml(string $html): string
    {
        $html = (string) preg_replace('#<(script|iframe|object|embed|form)\b[^>]*>.*?</\1\s*>#is', '', $html);
        $html = (string) preg_replace('#<(script|iframe|object|embed|form|base|meta\s+http-equiv)\b[^>]*/?>#i', '', $html);
        $html = (string) preg_replace('/\s+on[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $html);

        return (string) preg_replace('/(href|src)\s*=\s*(["\'])\s*javascript:[^"\']*\2/i', '$1=$2#$2', $html);
    }
}
