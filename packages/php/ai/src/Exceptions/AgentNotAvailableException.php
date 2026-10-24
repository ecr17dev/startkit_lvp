<?php

namespace Starter\Ai\Exceptions;

use RuntimeException;

/**
 * The requested agent does not exist, is not published or the user may not
 * use it on this surface.
 */
class AgentNotAvailableException extends RuntimeException
{
    public function __construct(?string $message = null)
    {
        parent::__construct($message ?? (string) __('No AI agent is available for you. Ask an administrator to publish one.'));
    }
}
