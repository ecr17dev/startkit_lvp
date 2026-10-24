<?php

namespace Starter\Ai\Exceptions;

use RuntimeException;

/**
 * The per-user request rate or concurrency of an agent was exceeded.
 */
class AgentLimitExceededException extends RuntimeException
{
    public function __construct(string $message, public readonly int $retryAfter = 0)
    {
        parent::__construct($message);
    }
}
