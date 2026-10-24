<?php

namespace Starter\Administration\Mail;

use Closure;

/**
 * A template the application sends (login code, invitation...), with its
 * variables and its default content. Packages define them in their service
 * providers; administrators override them in the panel. Names and
 * descriptions are English translation keys, translated when shown.
 */
final readonly class MailTemplateDefinition
{
    /**
     * @param  string  $category  access, ai, automation or system
     * @param  array<string, array{description: string, sample: string}>  $variables  name => description and preview value
     * @param  Closure(): array{subject: string, body: string}  $default  in the current locale, Markdown
     */
    public function __construct(
        public string $key,
        public string $category,
        public string $name,
        public string $description,
        public array $variables,
        public Closure $default,
    ) {}

    /**
     * @return array{subject: string, body: string}
     */
    public function defaults(): array
    {
        return ($this->default)();
    }
}
