<?php

namespace App\Ai;

use App\Models\AiPrompt;
use App\Models\User;

/**
 * Request scoped context attached to the AI usage logs.
 */
class UsageContext
{
    public ?User $user = null;

    public ?AiPrompt $prompt = null;

    public string $source = 'web';

    /**
     * Set the context for the calls performed inside the callback.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    public function run(?User $user, ?AiPrompt $prompt, string $source, callable $callback): mixed
    {
        [$previousUser, $previousPrompt, $previousSource] = [$this->user, $this->prompt, $this->source];

        $this->user = $user;
        $this->prompt = $prompt;
        $this->source = $source;

        try {
            return $callback();
        } finally {
            [$this->user, $this->prompt, $this->source] = [$previousUser, $previousPrompt, $previousSource];
        }
    }

    /**
     * Start a context without restoring it (used by streamed responses).
     */
    public function set(?User $user, ?AiPrompt $prompt, string $source): void
    {
        $this->user = $user;
        $this->prompt = $prompt;
        $this->source = $source;
    }
}
