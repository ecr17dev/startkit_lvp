<?php

namespace Starter\Knowledge\Exceptions;

use RuntimeException;

/**
 * Temporary condition (provider busy, rate limited, no free slot): the
 * queue job is released and retried after the delay, without counting as
 * a failure of the content.
 */
class RetryLaterException extends RuntimeException
{
    public function __construct(string $message, public readonly int $delay = 15)
    {
        parent::__construct($message);
    }
}
