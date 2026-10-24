<?php

namespace Starter\Knowledge\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Starter\Knowledge\Pipeline\JobRunner;
use Throwable;

/**
 * Queue carrier of a durable RAG job (the state lives in rag_jobs). The
 * runner claims the record atomically, so a duplicated or recovered
 * message never processes the same job twice at the same time.
 */
class ProcessRagJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 1800;

    public int $tries = 0;

    public int $maxExceptions = 3;

    public function __construct(public string $ragJobId)
    {
        if (is_string($queue = config('starter.rag.indexing.queue')) && $queue !== '') {
            $this->onQueue($queue);
        }
    }

    public function retryUntil(): \DateTimeInterface
    {
        return now()->addHours(12);
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [10, 60, 300];
    }

    public function handle(JobRunner $runner): void
    {
        $runner->run($this->ragJobId, fn (int $delay) => $this->release($delay));
    }

    public function failed(?Throwable $exception): void
    {
        app(JobRunner::class)->markFailed($this->ragJobId, $exception);
    }
}
