<?php

namespace App\Actions\Mail;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Starter\Administration\Mail\MailTemplates;
use Starter\Administration\Models\MailTemplate;

/**
 * Create a custom template (key custom.<slug>) for the automations,
 * starting from the content of another template or the announcement.
 */
class CreateMailTemplate
{
    public function __construct(protected MailTemplates $templates) {}

    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'locale' => ['required', Rule::in(array_keys((array) config('starter.locales')))],
            'from' => ['nullable', 'string', 'max:120'],
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function handle(User $actor, array $input): MailTemplate
    {
        Gate::forUser($actor)->authorize('mail.manage');

        $data = Validator::make($input, self::rules())->validate();
        $base = Str::slug($data['name']) ?: 'template';
        $key = 'custom.'.$base;

        for ($suffix = 2; $this->templates->exists($key); $suffix++) {
            $key = "custom.{$base}-{$suffix}";
        }

        $source = $this->templates->source($data['from'] ?? 'automation.announcement', $data['locale'])
            ?? $this->templates->source('automation.announcement', $data['locale']);

        $template = MailTemplate::query()->create([
            'key' => $key,
            'locale' => $data['locale'],
            'name' => $data['name'],
            'subject' => $source['subject'] ?? $data['name'],
            'body' => $source['body'] ?? '',
            'format' => $source['format'] ?? 'markdown',
            'updated_by' => $actor->id,
        ]);

        audit('mail.template_created', $template, ['key' => $key, 'locale' => $template->locale], $actor);

        return $template;
    }
}
