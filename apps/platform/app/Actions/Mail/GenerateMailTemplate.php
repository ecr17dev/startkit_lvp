<?php

namespace App\Actions\Mail;

use App\Ai\Mail\MailTemplateWriter;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Starter\Administration\Mail\MailTemplates;
use Starter\Ai\AiGateway;
use Starter\Ai\Enums\Feature;
use Starter\Ai\Models\AiModel;

/**
 * Draft a template (subject and body) from a description with the default
 * text model, through the AI gateway (ledger, budgets). Nothing is saved:
 * the editor shows the draft. With the simulated provider the draft is
 * built locally and marked as simulated.
 */
class GenerateMailTemplate
{
    public function __construct(
        protected MailTemplates $templates,
        protected AiGateway $gateway,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'description' => ['required', 'string', 'min:10', 'max:2000'],
            'format' => ['required', Rule::in(MailTemplates::FORMATS)],
            'locale' => ['required', Rule::in(array_keys((array) config('starter.locales')))],
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{subject: string, body: string, format: string, simulated: bool, unknown: list<string>}
     */
    public function handle(User $actor, string $key, array $input): array
    {
        Gate::forUser($actor)->authorize('mail.manage');

        $data = Validator::make($input, self::rules())->validate();
        $model = AiModel::query()->with('connection')->usable()->forFunction('text')->orderByDesc('is_default')->first();

        if ($model === null) {
            throw ValidationException::withMessages(['description' => __('There is no enabled text model. Add one in AI → Model catalog.')]);
        }

        $variables = array_map(fn (array $variable) => __($variable['description']), $this->templates->variables($key));
        $language = (string) (config('starter.locales')[$data['locale']] ?? $data['locale']);

        if ($model->connection->driver->isSimulated()) {
            $draft = $this->simulated($data['description'], $data['format'], $data['locale']);
        } else {
            $response = $this->gateway->run(
                $model,
                Feature::Other,
                $actor,
                fn (string $provider, string $name) => (new MailTemplateWriter($data['format'], $language, $variables))
                    ->prompt($data['description'], provider: $provider, model: $name),
                ['input_chars' => 3000 + mb_strlen($data['description']), 'max_output_tokens' => 3000],
            );

            $structured = $response->toArray();
            $draft = [
                'subject' => Str::limit(trim((string) ($structured['subject'] ?? '')), 250, ''),
                'body' => trim((string) ($structured['body'] ?? '')),
                'simulated' => false,
            ];
        }

        if ($data['format'] === 'html') {
            $draft['body'] = SaveMailTemplate::cleanHtml($draft['body']);
        }

        audit('mail.template_generated', null, ['key' => $key, 'format' => $data['format'], 'model' => $model->model, 'simulated' => $draft['simulated']], $actor);

        return [
            ...$draft,
            'format' => $data['format'],
            'unknown' => $this->templates->unknownVariables($key, $draft['subject'].' '.$draft['body']),
        ];
    }

    /**
     * A local draft from the description (no provider is called).
     *
     * @return array{subject: string, body: string, simulated: bool}
     */
    protected function simulated(string $description, string $format, string $locale): array
    {
        $previous = app()->getLocale();
        app()->setLocale($locale);

        try {
            $text = trim((string) preg_replace('/\s+/', ' ', $description));
            $subject = Str::limit((string) Str::of($text)->before('.')->ucfirst(), 80);
            $paragraph = __('[Simulated draft] :text', ['text' => $text]);

            if ($format === 'html') {
                $body = '<div style="max-width:600px;margin:0 auto;font-family:system-ui,sans-serif;color:#18181b">'
                    .'<h1 style="font-size:22px">'.e(__('Hello :name!', ['name' => '{{ recipient_name }}'])).'</h1>'
                    .'<p style="font-size:16px;line-height:1.5">'.e($paragraph).'</p>'
                    .'<p style="text-align:center;margin:32px 0"><a href="{{ app_url }}" style="background:#18181b;color:#ffffff;padding:12px 20px;border-radius:6px;text-decoration:none">'.e(__('Open :app', ['app' => '{{ app_name }}'])).'</a></p>'
                    .'<p style="font-size:14px;color:#52525b">'.e(__('Regards')).',<br>{{ app_name }}</p></div>';
            } else {
                $body = implode("\n\n", [
                    '# '.__('Hello :name!', ['name' => '{{ recipient_name }}']),
                    $paragraph,
                    '[['.__('Open :app', ['app' => '{{ app_name }}']).'|{{ app_url }}]]',
                    __('Regards').",<br>\n{{ app_name }}",
                ]);
            }

            return ['subject' => $subject.' · {{ app_name }}', 'body' => $body, 'simulated' => true];
        } finally {
            app()->setLocale($previous);
        }
    }
}
