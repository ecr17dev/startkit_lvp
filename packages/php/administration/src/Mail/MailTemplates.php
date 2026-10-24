<?php

namespace Starter\Administration\Mail;

use Illuminate\Mail\Markdown;
use Starter\Administration\Models\MailTemplate;

/**
 * Registry and renderer of the email templates.
 *
 * Content is never compiled as Blade: variables are `{{ name }}` placeholders
 * replaced with escaped values, `[[Label|url]]` on its own line is a button,
 * and Markdown bodies are rendered with the application mail theme. HTML
 * bodies are sent as written (administrators only).
 */
class MailTemplates
{
    public const FORMATS = ['markdown', 'html'];

    /**
     * @var array<string, MailTemplateDefinition>
     */
    protected array $definitions = [];

    public function __construct(protected Markdown $markdown) {}

    public function define(MailTemplateDefinition $definition): void
    {
        $this->definitions[$definition->key] = $definition;
    }

    /**
     * @return array<string, MailTemplateDefinition>
     */
    public function definitions(): array
    {
        return $this->definitions;
    }

    public function definition(string $key): ?MailTemplateDefinition
    {
        return $this->definitions[$key] ?? null;
    }

    public function exists(string $key): bool
    {
        return isset($this->definitions[$key]) || MailTemplate::query()->where('key', $key)->exists();
    }

    /**
     * Variables every template can use, with preview values.
     *
     * @return array<string, array{description: string, sample: string}>
     */
    public function globalVariables(): array
    {
        return [
            'app_name' => ['description' => 'Application name', 'sample' => $this->appName()],
            'app_url' => ['description' => 'Application address', 'sample' => (string) config('app.url')],
            'login_url' => ['description' => 'Login page', 'sample' => url('/login')],
            'recipient_name' => ['description' => 'Name of the recipient', 'sample' => 'Ana Pérez'],
            'recipient_email' => ['description' => 'Email of the recipient', 'sample' => 'ana@example.com'],
            'year' => ['description' => 'Current year', 'sample' => (string) now()->year],
        ];
    }

    /**
     * Variables of a template (its own and the global ones).
     *
     * @return array<string, array{description: string, sample: string}>
     */
    public function variables(string $key): array
    {
        return [...($this->definitions[$key]->variables ?? []), ...$this->globalVariables()];
    }

    /**
     * The subject, body and format a template uses in a locale: the stored
     * version or the default of its definition.
     *
     * @return array{subject: string, body: string, format: string, customized: bool, name: string|null}|null
     */
    public function source(string $key, ?string $locale = null): ?array
    {
        $locale = $locale ?? app()->getLocale();
        $stored = MailTemplate::query()
            ->where('key', $key)
            ->orderByRaw('case when locale = ? then 0 when locale = ? then 1 else 2 end', [$locale, (string) config('app.fallback_locale')])
            ->first();

        if ($stored !== null && ($stored->locale === $locale || ! isset($this->definitions[$key]))) {
            return ['subject' => $stored->subject, 'body' => $stored->body, 'format' => $stored->format, 'customized' => true, 'name' => $stored->name];
        }

        $definition = $this->definitions[$key] ?? null;

        if ($definition === null) {
            return null;
        }

        $previous = app()->getLocale();
        app()->setLocale($locale);

        try {
            $default = $definition->defaults();
        } finally {
            app()->setLocale($previous);
        }

        return [...$default, 'format' => 'markdown', 'customized' => false, 'name' => null];
    }

    /**
     * Render a template for sending.
     *
     * @param  array<string, scalar|null>  $variables
     */
    public function render(string $key, array $variables = [], ?string $locale = null): RenderedMail
    {
        $source = $this->source($key, $locale) ?? ['subject' => $key, 'body' => '', 'format' => 'markdown'];

        return $this->compose($source['subject'], $source['body'], $source['format'], $this->values($variables));
    }

    /**
     * Render arbitrary content (editor preview, tests) with the preview
     * values of the template variables.
     *
     * @param  array<string, scalar|null>  $variables
     */
    public function preview(string $key, string $subject, string $body, string $format, array $variables = []): RenderedMail
    {
        $samples = array_map(fn (array $variable) => $variable['sample'], $this->variables($key));

        return $this->compose($subject, $body, $format, $this->values([...$samples, ...$variables]));
    }

    /**
     * @param  array<string, scalar|null>  $values
     */
    public function compose(string $subject, string $body, string $format, array $values): RenderedMail
    {
        $subject = trim((string) preg_replace('/\s+/', ' ', $this->fill($subject, $values, false)));

        if ($format === 'html') {
            $html = $this->buttons($this->fill($body, $values, true), true);

            return new RenderedMail($subject, $html, $this->textFromHtml($html));
        }

        $markdown = $this->fill($body, $values, true);
        $html = $this->markdown->render('starter-admin::mail.template', ['body' => $this->buttons($markdown, true)])->toHtml();
        $text = $this->markdown->renderText('starter-admin::mail.template', ['body' => $this->buttons($markdown, false)])->toHtml();

        return new RenderedMail($subject, $html, trim(html_entity_decode($text, ENT_QUOTES | ENT_HTML5)));
    }

    /**
     * Placeholders used in a text that the template does not define.
     *
     * @return list<string>
     */
    public function unknownVariables(string $key, string $text): array
    {
        preg_match_all('/\{\{\s*([a-zA-Z][a-zA-Z0-9_]*)\s*\}\}/', $text, $matches);

        return array_values(array_unique(array_diff($matches[1], array_keys($this->variables($key)))));
    }

    /**
     * Global values merged with the given ones.
     *
     * @param  array<string, scalar|null>  $variables
     * @return array<string, scalar|null>
     */
    protected function values(array $variables): array
    {
        return [
            'app_name' => $this->appName(),
            'app_url' => (string) config('app.url'),
            'login_url' => url('/login'),
            'year' => (string) now()->year,
            'recipient_name' => '',
            'recipient_email' => '',
            ...$variables,
        ];
    }

    /**
     * Replace the known placeholders; unknown ones stay visible so mistakes
     * show up in the preview.
     *
     * @param  array<string, scalar|null>  $values
     */
    protected function fill(string $text, array $values, bool $escape): string
    {
        return (string) preg_replace_callback('/\{\{\s*([a-zA-Z][a-zA-Z0-9_]*)\s*\}\}/', function (array $match) use ($values, $escape) {
            if (! array_key_exists($match[1], $values)) {
                return $match[0];
            }

            $value = (string) $values[$match[1]];

            return $escape ? e($value) : $value;
        }, $text);
    }

    /**
     * `[[Label|url]]` on its own line becomes a button (HTML) or "Label: url"
     * (plain text). Only http(s) and mailto links are allowed.
     */
    protected function buttons(string $text, bool $html): string
    {
        return (string) preg_replace_callback('/^[ \t]*\[\[([^|\]\n]+)\|([^\]\n]+)\]\][ \t]*$/m', function (array $match) use ($html) {
            $label = trim($match[1]);
            $url = trim(html_entity_decode($match[2], ENT_QUOTES | ENT_HTML5));

            if (! preg_match('#^(https?://|mailto:)#i', $url)) {
                return $label;
            }

            if (! $html) {
                return "{$label}: {$url}";
            }

            $href = e($url);

            return '<table class="action" align="center" width="100%" cellpadding="0" cellspacing="0" role="presentation"><tr><td align="center">'
                .'<table border="0" cellpadding="0" cellspacing="0" role="presentation"><tr><td>'
                ."<a href=\"{$href}\" class=\"button button-primary\" target=\"_blank\" rel=\"noopener\">{$label}</a>"
                .'</td></tr></table></td></tr></table>';
        }, $text);
    }

    protected function textFromHtml(string $html): string
    {
        $html = (string) preg_replace('#<(script|style|head)[^>]*>.*?</\1>#is', '', $html);
        $html = (string) preg_replace('#<a[^>]+href="([^"]+)"[^>]*>(.*?)</a>#is', '$2 ($1)', $html);
        $html = (string) preg_replace('#<(br|/p|/h[1-6]|/tr|/li|/div)[^>]*>#i', "\n", $html);
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5);

        return trim((string) preg_replace("/\n{3,}/", "\n\n", (string) preg_replace('/[ \t]+/', ' ', $text)));
    }

    protected function appName(): string
    {
        return (string) (settings('branding.app_name') ?: config('app.name'));
    }
}
