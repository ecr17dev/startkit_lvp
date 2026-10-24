<?php

namespace Starter\Ai\Agents;

use Laravel\Ai\Attributes\MaxTokens;
use Laravel\Ai\Attributes\Timeout;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * Writes the title of a conversation from its first message.
 */
#[MaxTokens(24)]
#[Timeout(20)]
class TitleAgent implements Agent
{
    use Promptable;

    public function instructions(): Stringable|string
    {
        return 'Write a short title (at most 6 words) for a conversation that starts with the user message. Use the language of the message. Reply only with the title, without quotes and without a final period.';
    }
}
