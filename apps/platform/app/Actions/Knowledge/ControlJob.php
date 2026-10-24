<?php

namespace App\Actions\Knowledge;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Starter\Knowledge\Enums\JobStatus;
use Starter\Knowledge\Models\Collection;
use Starter\Knowledge\Models\RagJob;
use Starter\Knowledge\Pipeline\RagJobs;
use Starter\Knowledge\Pipeline\Reindexer;
use Throwable;

/**
 * Cancel, retry or resume (with a confirmed budget) jobs; start reindexes.
 */
class ControlJob
{
    public function __construct(protected RagJobs $jobs, protected Reindexer $reindexer) {}

    public function cancel(User $actor, RagJob $job): RagJob
    {
        Gate::forUser($actor)->authorize('rag.jobs.manage');

        if ($job->isFinished()) {
            throw ValidationException::withMessages(['job' => __('The job already finished.')]);
        }

        $this->jobs->cancel($job);
        audit('rag.jobs.cancelled', null, ['job' => $job->id, 'type' => $job->type->value], $actor);

        return $job->refresh();
    }

    public function retry(User $actor, RagJob $job): RagJob
    {
        Gate::forUser($actor)->authorize('rag.jobs.manage');

        if (! in_array($job->status, [JobStatus::Failed, JobStatus::Cancelled], true) && ! ($job->status === JobStatus::Succeeded && $job->error_type === 'partial')) {
            throw ValidationException::withMessages(['job' => __('Only failed or cancelled jobs can be retried.')]);
        }

        $job = $this->jobs->retry($job);
        audit('rag.jobs.retried', null, ['job' => $job->id], $actor);

        return $job;
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function resume(User $actor, RagJob $job, array $input): RagJob
    {
        Gate::forUser($actor)->authorize('rag.jobs.manage');

        /** @var array{max_cost: numeric-string|float|int} $data */
        $data = Validator::make($input, [
            /** Maximum cost in USD confirmed for this ingestion. */
            'max_cost' => ['required', 'numeric', 'min:0', 'max:100000'],
        ])->validate();

        if ($job->status !== JobStatus::Paused) {
            throw ValidationException::withMessages(['job' => __('Only paused jobs can be resumed.')]);
        }

        $job = $this->jobs->resume($job, number_format((float) $data['max_cost'], 6, '.', ''));
        audit('rag.jobs.resumed', null, ['job' => $job->id, 'max_cost' => $job->max_cost], $actor);

        return $job;
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function reindex(User $actor, Collection $collection, array $input = []): RagJob
    {
        Gate::forUser($actor)->authorize('rag.jobs.manage');

        /** @var array{max_cost?: numeric-string|float|int|null} $data */
        $data = Validator::make($input, ['max_cost' => ['nullable', 'numeric', 'min:0', 'max:100000']])->validate();

        try {
            $job = $this->reindexer->start($collection, $actor->id, isset($data['max_cost']) ? number_format((float) $data['max_cost'], 6, '.', '') : null);
        } catch (Throwable $exception) {
            throw ValidationException::withMessages(['collection' => $exception->getMessage()]);
        }

        audit('rag.collections.reindex', $collection, ['job' => $job->id], $actor);

        return $job;
    }
}
