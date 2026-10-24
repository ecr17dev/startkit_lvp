<?php

namespace App\Actions\Knowledge;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Starter\Knowledge\Enums\Capability;
use Starter\Knowledge\Enums\JobStatus;
use Starter\Knowledge\Enums\JobType;
use Starter\Knowledge\Knowledge;
use Starter\Knowledge\Models\RagJob;
use Starter\Knowledge\Models\Source;
use Starter\Knowledge\Pipeline\RagJobs;

/**
 * Starts a sync of a source; an open sync is returned instead of a second
 * one (idempotent).
 */
class SyncSource
{
    public function __construct(protected Knowledge $knowledge, protected RagJobs $jobs) {}

    /**
     * @param  array{force?: bool, max_cost?: string|null}  $options
     */
    public function handle(User $actor, Source $source, array $options = []): RagJob
    {
        Gate::forUser($actor)->authorize('rag.sources.manage');
        $this->knowledge->ensureFor($source->collection, Capability::Ingest);

        if (! $source->isActive()) {
            throw ValidationException::withMessages(['source' => __('The source is paused.')]);
        }

        $open = $source->jobs()->where('type', JobType::SyncSource->value)->whereIn('status', JobStatus::open())->latest()->first();

        if ($open !== null) {
            return $open;
        }

        $job = $this->jobs->create(JobType::SyncSource, [
            'collection_id' => $source->collection_id,
            'source_id' => $source->id,
            'created_by' => $actor->id,
            'max_cost' => $options['max_cost'] ?? null,
            'payload' => ['force' => (bool) ($options['force'] ?? false), 'confirmed' => isset($options['max_cost'])],
        ]);

        audit('rag.sources.sync', $source, ['job' => $job->id, 'force' => (bool) ($options['force'] ?? false)], $actor);

        return $job;
    }
}
