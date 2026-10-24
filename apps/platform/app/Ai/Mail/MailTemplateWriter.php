<?php

namespace App\Ai\Mail;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Attributes\MaxTokens;
use Laravel\Ai\Attributes\Timeout;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * Writes the subject and body of an email template from a description,
 * using only the variables the template offers.
 */
#[MaxTokens(3000)]
#[Timeout(90)]
class MailTemplateWriter implements Agent, HasStructuredOutput
{
    use Promptable;

    /**
     * @param  array<string, string>  $variables  name => description
     */
    public function __construct(
        public string $format,
        public string $language,
        public array $variables,
    ) {}

    public function instructions(): Stringable|string
    {
        $variables = collect($this->variables)->map(fn (string $description, string $name) => "{{ {$name} }}: {$description}")->implode("\n");
        $body = $this->format === 'html'
            ? 'The body is a complete, responsive HTML email: a single centered column up to 600px, inline CSS only, system fonts, no scripts, no external stylesheets, no forms and no images unless the description gives their absolute URL.'
            : 'The body is Markdown: "# " for the main heading, short paragraphs, **bold** for emphasis and a button as a line with [[Button text|{{ url_variable }}]] using one of the URL variables.';

        return "You write email templates for a web application. Write in {$this->language}, with a clear, friendly and professional tone, short and scannable. "
            ."{$body} Use variables only with the exact syntax {{ name }} and only from this list:\n{$variables}\n"
            .'Never invent other variables, links, prices or promises that the description does not include. The subject is plain text up to 120 characters.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'subject' => $schema->string()->required(),
            'body' => $schema->string()->required(),
        ];
    }
}
