<?php

namespace Starter\Knowledge\Pipeline;

use Illuminate\Support\Facades\DB;
use Starter\Knowledge\Enums\JobStatus;
use Starter\Knowledge\Enums\JobType;
use Starter\Knowledge\Models\RagJob;
use Starter\Knowledge\Models\Source;

/**
 * What happens when jobs end: a parent advances with its children and is
 * finished by the last one (under a row lock, so exactly once); a finished
 * sync updates its source and the statistics of the index versions; a
 * finished reindex publishes its version.
 */
class Completion
{
    public function __construct(
        protected IndexVersions $versions,
        protected Reindexer $reindexer,
    ) {}

    public function finished(RagJob $job): void
    {
        if ($job->parent_id !== null) {
            $this->childChanged($job);

            return;
        }

        $this->afterRoot($job);
    }

    /**
     * Progress of the parent; finish it when no child is open.
     */
    public function childChanged(RagJob $child): void
    {
        if ($child->parent_id === null) {
            return;
        }

        $parent = DB::transaction(function () use ($child) {
            $parent = RagJob::query()->lockForUpdate()->find($child->parent_id);

            if ($parent === null || $parent->isFinished()) {
                return null;
            }

            $counts = RagJob::query()->where('parent_id', $parent->id)
                ->selectRaw('status, count(*) AS total')->groupBy('status')->pluck('total', 'status')->map(fn ($total) => (int) $total);
            $open = $counts->only([JobStatus::Queued->value, JobStatus::Running->value, JobStatus::Retrying->value])->sum();
            $paused = (int) ($counts[JobStatus::Paused->value] ?? 0);
            $failed = (int) ($counts[JobStatus::Failed->value] ?? 0);
            $cancelled = (int) ($counts[JobStatus::Cancelled->value] ?? 0);
            $total = $counts->sum();

            $parent->forceFill(['progress_done' => $total - $open - $paused, 'progress_total' => $total, 'heartbeat_at' => now()]);

            if ($open > 0) {
                $parent->save();

                return null;
            }

            if ($paused > 0) {
                $parent->forceFill(['status' => JobStatus::Paused, 'error' => $child->error, 'error_type' => 'paused'])->save();

                return null;
            }

            $status = match (true) {
                $parent->cancel_requested_at !== null || ($cancelled > 0 && $failed + $cancelled === $total) => JobStatus::Cancelled,
                $failed > 0 && $failed === $total => JobStatus::Failed,
                default => JobStatus::Succeeded,
            };

            $parent->forceFill([
                'status' => $status,
                'finished_at' => now(),
                'reserved' => 0,
                'error' => $failed > 0 ? __(':failed of :total documents failed to index.', ['failed' => $failed, 'total' => $total]) : null,
                'error_type' => $failed > 0 ? 'partial' : null,
                'payload' => [...($parent->payload ?? []), 'waiting' => false],
            ])->save();

            return $parent;
        });

        if ($parent !== null) {
            $this->afterRoot($parent);
        }
    }

    protected function afterRoot(RagJob $job): void
    {
        $job->loadMissing('collection');

        if ($job->type === JobType::Reindex) {
            $this->reindexer->complete($job);
        }

        if ($job->type === JobType::SyncSource && $job->source_id !== null) {
            $source = Source::query()->find($job->source_id);

            $source?->forceFill([
                'last_sync_at' => now(),
                'last_job_id' => $job->id,
                'last_error' => $job->status === JobStatus::Succeeded && $job->error_type !== 'partial' ? null : $job->error,
            ])->save();
        }

        if ($job->collection !== null && in_array($job->type, [JobType::SyncSource, JobType::IndexDocument, JobType::PurgeDocument, JobType::PurgeSource], true)) {
            foreach ($this->versions->targets($job->collection) as $version) {
                $this->versions->refreshStats($version);
            }
        }
    }
}
