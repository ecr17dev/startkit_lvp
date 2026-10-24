<?php

namespace Starter\Knowledge\Pipeline;

use Illuminate\Support\Facades\DB;
use Starter\Knowledge\Enums\JobStatus;
use Starter\Knowledge\Enums\JobType;
use Starter\Knowledge\Jobs\ProcessRagJob;
use Starter\Knowledge\Models\RagJob;

/**
 * Creates, dispatches and controls durable jobs (cancel, retry, resume).
 */
class RagJobs
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(JobType $type, array $attributes = [], bool $dispatch = true): RagJob
    {
        $job = RagJob::query()->create([
            'type' => $type,
            'status' => JobStatus::Queued,
            'trigger' => 'manual',
            ...$attributes,
        ]);

        if ($dispatch) {
            $this->dispatch($job);
        }

        return $job;
    }

    public function dispatch(RagJob $job): void
    {
        // After commit: the worker must see the record...
        ProcessRagJob::dispatch($job->id)->afterCommit();
    }

    /**
     * Cooperative cancellation: queued jobs stop at once, running jobs at
     * their next checkpoint. Children are cancelled with their parent.
     */
    public function cancel(RagJob $job): void
    {
        $ids = [$job->id, ...$job->children()->pluck('id')->all()];

        DB::transaction(function () use ($ids) {
            RagJob::query()->whereIn('id', $ids)->whereIn('status', JobStatus::open())->update(['cancel_requested_at' => now()]);
            RagJob::query()->whereIn('id', $ids)->whereIn('status', [JobStatus::Queued->value, JobStatus::Retrying->value, JobStatus::Paused->value])
                ->update(['status' => JobStatus::Cancelled->value, 'finished_at' => now(), 'error' => __('Cancelled by an administrator.')]);
        });

        // A parent waiting only on children is finished here...
        $job->refresh();

        if ($job->status === JobStatus::Running && ! $job->children()->whereIn('status', JobStatus::open())->exists() && ($job->payload['waiting'] ?? false)) {
            $job->forceFill(['status' => JobStatus::Cancelled, 'finished_at' => now()])->save();
        }
    }

    /**
     * Retry a failed or cancelled job (and its failed children).
     */
    public function retry(RagJob $job): RagJob
    {
        if ($job->children()->exists()) {
            foreach ($job->children()->whereIn('status', [JobStatus::Failed->value, JobStatus::Cancelled->value])->get() as $child) {
                $this->requeue($child);
            }

            $job->forceFill(['status' => JobStatus::Running, 'finished_at' => null, 'cancel_requested_at' => null, 'error' => null])->save();

            return $job;
        }

        return $this->requeue($job);
    }

    /**
     * Resume a paused job, optionally with a new confirmed budget.
     */
    public function resume(RagJob $job, ?string $maxCost = null): RagJob
    {
        $job->forceFill(array_filter(['max_cost' => $maxCost, 'error' => null], fn ($value) => $value !== null))->save();

        if ($job->payload['waiting'] ?? false) {
            foreach ($job->children()->where('status', JobStatus::Paused->value)->get() as $child) {
                $this->requeue($child);
            }

            $job->forceFill(['status' => JobStatus::Running, 'error' => null])->save();

            return $job;
        }

        return $this->requeue($job);
    }

    protected function requeue(RagJob $job): RagJob
    {
        $job->forceFill([
            'status' => JobStatus::Queued,
            'cancel_requested_at' => null,
            'finished_at' => null,
            'error' => null,
            'error_type' => null,
        ])->save();

        $this->dispatch($job);

        return $job;
    }
}
