<?php

namespace Starter\Ai\Agents;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * Agent of a lab run: the same instructions and output limit for every
 * model compared.
 */
class LabAgent implements Agent
{
    use Promptable;

    public function __construct(
        protected ?string $systemPrompt = null,
        protected ?int $outputTokens = null,
    ) {}

    public function instructions(): Stringable|string
    {
        return $this->systemPrompt ?: 'You are a helpful assistant.';
    }

    public function maxTokens(): ?int
    {
        return $this->outputTokens;
    }

    public function timeout(): int
    {
        return 120;
    }
}
