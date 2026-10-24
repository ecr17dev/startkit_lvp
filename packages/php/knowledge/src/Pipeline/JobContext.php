<?php

namespace Starter\Knowledge\Pipeline;

use Closure;
use Illuminate\Support\Facades\DB;
use Starter\Knowledge\Enums\JobStage;
use Starter\Knowledge\Exceptions\JobCancelledException;
use Starter\Knowledge\Models\RagJob;

/**
 * Bookkeeping of a running job: current stage, time per stage, units,
 * progress, heartbeat and cooperative cancellation. Writes are buffered
 * and flushed at checkpoints (at most every two seconds).
 */
class JobContext
{
    /**
     * @var array<string, int>
     */
    protected array $timings;

    /**
     * @var array<string, int>
     */
    protected array $units;

    protected float $flushedAt = 0;

    public function __construct(public RagJob $job)
    {
        $this->timings = $job->timings ?? [];
        $this->units = $job->units ?? [];
    }

    /**
     * Run a stage measuring its duration.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function stage(JobStage $stage, Closure $callback): mixed
    {
        if ($this->job->stage !== $stage) {
            $this->job->stage = $stage;
            $this->flushedAt = 0;
        }

        $started = hrtime(true);

        try {
            return $callback();
        } finally {
            $this->timings[$stage->value] = ($this->timings[$stage->value] ?? 0) + (int) ((hrtime(true) - $started) / 1_000_000);
        }
    }

    public function count(string $unit, int $amount = 1): void
    {
        $this->units[$unit] = ($this->units[$unit] ?? 0) + $amount;
    }

    public function unit(string $unit): int
    {
        return $this->units[$unit] ?? 0;
    }

    public function progress(int $done, ?int $total = null): void
    {
        $this->job->progress_done = $done;

        if ($total !== null) {
            $this->job->progress_total = $total;
        }
    }

    /**
     * Heartbeat + flush + cancellation check.
     *
     * @throws JobCancelledException
     */
    public function checkpoint(bool $force = false): void
    {
        if (! $force && microtime(true) - $this->flushedAt < 2) {
            return;
        }

        $this->flush();

        $cancelled = DB::table('rag_jobs')->where('id', $this->job->id)->value('cancel_requested_at');

        if ($cancelled !== null) {
            throw new JobCancelledException((string) __('Cancelled by an administrator.'));
        }
    }

    public function flush(): void
    {
        $this->flushedAt = microtime(true);
        $this->job->forceFill([
            'timings' => $this->timings,
            'units' => $this->units,
            'heartbeat_at' => now(),
        ])->save();
    }
}
