<?php

namespace App\Ai\Agents;

use App\Models\AiPrompt;
use Laravel\Ai\Concerns\RemembersConversations;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * General purpose assistant whose instructions come from a system
 * prompt managed in the admin panel (Admin → AI → Prompts).
 */
class Assistant implements Agent, Conversational, HasTools
{
    use Promptable, RemembersConversations;

    public const FALLBACK_INSTRUCTIONS = 'You are a helpful assistant. Answer clearly and concisely in the language of the user.';

    /**
     * @param  array<string, string|int|float|null>  $variables
     */
    public function __construct(
        public ?AiPrompt $systemPrompt = null,
        public array $variables = [],
    ) {}

    /**
     * Get the instructions that the agent should follow.
     */
    public function instructions(): Stringable|string
    {
        return $this->systemPrompt?->render($this->variables) ?? self::FALLBACK_INSTRUCTIONS;
    }

    /**
     * Get the tools available to the agent.
     *
     * @return Tool[]
     */
    public function tools(): iterable
    {
        return [];
    }

    /**
     * Get the sampling temperature configured on the system prompt.
     */
    public function temperature(): ?float
    {
        return $this->systemPrompt?->temperature !== null ? (float) $this->systemPrompt->temperature : null;
    }

    /**
     * Get the maximum output tokens configured on the system prompt.
     */
    public function maxTokens(): ?int
    {
        return $this->systemPrompt?->max_tokens;
    }

    /**
     * Get the maximum number of conversation messages to include in context.
     */
    protected function maxConversationMessages(): int
    {
        return 50;
    }
}
