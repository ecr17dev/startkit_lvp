<?php

namespace Starter\Knowledge\Ingestion\Sources;

use Closure;

/**
 * State shared by a driver and the sync: whether discovery was cut by a
 * limit (then unseen documents are not deleted) and a checkpoint callback
 * for cancellation and heartbeats.
 */
final class SyncContext
{
    public bool $truncated = false;

    /**
     * @var list<string>
     */
    public array $warnings = [];

    /**
     * @param  Closure(): void  $checkpoint
     */
    public function __construct(public Closure $checkpoint) {}

    public function checkpoint(): void
    {
        ($this->checkpoint)();
    }
}
