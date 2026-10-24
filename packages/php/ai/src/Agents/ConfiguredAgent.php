<?php

namespace Starter\Ai\Agents;

use Laravel\Ai\Concerns\RemembersConversations;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Promptable;
use Starter\Ai\Models\AgentVersion;
use Stringable;

/**
 * Laravel AI SDK agent built from a published (or tested draft) agent
 * version: instructions, tools, limits and conversation memory all come
 * from the server-side settings.
 */
class ConfiguredAgent implements Agent, Conversational, HasTools
{
    use Promptable, RemembersConversations;

    protected AgentConfig $config;

    /**
     * @param  array<string, string|int|float|null>  $variables
     * @param  list<Tool>  $tools
     */
    public function __construct(
        public readonly AgentVersion $version,
        protected array $variables = [],
        protected array $tools = [],
    ) {
        $this->config = $version->settings();
    }

    public function instructions(): Stringable|string
    {
        return $this->config->render($this->variables);
    }

    /**
     * @return list<Tool>
     */
    public function tools(): iterable
    {
        return $this->tools;
    }

    public function maxSteps(): int
    {
        return $this->tools === [] ? 1 : $this->config->maxSteps();
    }

    public function maxTokens(): ?int
    {
        return $this->config->maxTokens();
    }

    public function temperature(): ?float
    {
        return $this->config->temperature();
    }

    public function timeout(): int
    {
        return $this->config->timeout();
    }

    protected function maxConversationMessages(): int
    {
        return $this->config->historyMessages();
    }
}
