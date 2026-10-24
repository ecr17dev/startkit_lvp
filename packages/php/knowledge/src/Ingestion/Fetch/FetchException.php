<?php

namespace Starter\Knowledge\Ingestion\Fetch;

use RuntimeException;

class FetchException extends RuntimeException
{
    public function __construct(string $message, public readonly ?int $status = null, public readonly bool $retryable = false)
    {
        parent::__construct($message);
    }

    /**
     * The origin says the resource no longer exists.
     */
    public function gone(): bool
    {
        return in_array($this->status, [404, 410], true);
    }
}
