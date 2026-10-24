<?php

namespace Starter\Ai\Agents;

use Laravel\Ai\Attributes\MaxTokens;
use Laravel\Ai\Attributes\Timeout;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Promptable;

/**
 * Minimal explicit call used to verify that a model answers with the
 * credential of its connection.
 */
#[MaxTokens(16)]
#[Timeout(30)]
class PingAgent implements Agent
{
    use Promptable;

    public function instructions(): string
    {
        return 'Reply with the single word OK.';
    }
}
