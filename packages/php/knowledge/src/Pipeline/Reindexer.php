<?php

namespace Starter\Knowledge\Pipeline;

use Illuminate\Support\Facades\DB;
use RuntimeException;
use Starter\Knowledge\Enums\DocumentStatus;
use Starter\Knowledge\Enums\IndexVersionStatus;
use Starter\Knowledge\Enums\JobStage;
use Starter\Knowledge\Enums\JobStatus;
use Starter\Knowledge\Enums\JobType;
use Starter\Knowledge\Exceptions\JobPausedException;
use Starter\Knowledge\Models\Collection;
use Starter\Knowledge\Models\Document;
use Starter\Knowledge\Models\DocumentVersion;
use Starter\Knowledge\Models\IndexVersion;
use Starter\Knowledge\Models\RagJob;
use Starter\Knowledge\Store\VectorStore;

/**
 * Rebuilds a collection in a new index version (new profile or chunking)
 * from the stored document versions, while the active version keeps
 * answering. The new version is published only when every document was
 * indexed; then the previous one is superseded and purged by a job.
 */
class Reindexer
{
    public function __construct(
        protected RagJobs $jobs,
        protected Estimator $estimator,
        protected VectorStore $store,
    ) {}

    /**
     * Create the building version and its job.
     */
    public function start(Collection $collection, ?int $userId = null, ?string $maxCost = null, string $reason = 'reindex'): RagJob
    {
        return DB::transaction(function () use ($collection, $userId, $maxCost, $reason) {
            $locked = Collection::query()->lockForUpdate()->findOrFail($collection->id);

            if ($locked->indexVersions()->where('status', IndexVersionStatus::Building->value)->exists()) {
                throw new RuntimeException((string) __('A reindex of this collection is already in progress.'));
            }

            $version = $locked->indexVersions()->create([
                'embedding_profile_id' => $locked->embedding_profile_id,
                'number' => (int) $locked->indexVersions()->max('number') + 1,
                'status' => IndexVersionStatus::Building,
                'reason' => $reason,
                'chunking' => $locked->chunking,
                'created_by' => $userId,
            ]);

            return $this->jobs->create(JobType::Reindex, [
                'collection_id' => $locked->id,
                'index_version_id' => $version->id,
                'created_by' => $userId,
                'max_cost' => $maxCost,
                'payload' => ['confirmed' => $maxCost !== null],
            ]);
        });
    }

    public function run(JobContext $context): void
    {
        $job = $context->job;
        $version = $job->indexVersion ?? throw new RuntimeException((string) __('The index version no longer exists.'));
        $version->loadMissing('profile.aiModel');

        $documents = Document::query()->where('collection_id', $job->collection_id)->alive()
            ->whereNotNull('latest_version_id')
            ->whereIn('status', [DocumentStatus::Indexed->value, DocumentStatus::Indexing->value, DocumentStatus::Pending->value])
            ->get(['id', 'latest_version_id', 'source_id']);

        if ($job->max_cost === null && ! ($job->payload['confirmed'] ?? false)) {
            $characters = DocumentVersion::query()->whereIn('id', $documents->pluck('latest_version_id'))->selectRaw('char_length(content) AS length')->pluck('length')->map(fn ($length) => (int) $length)->all();
            $estimate = $this->estimator->estimate($characters, [$version]);
            $job->forceFill(['estimate' => $estimate]);

            if ($estimate['needs_confirmation']) {
                throw new JobPausedException((string) __('The reindex needs a confirmed budget: about :tokens tokens, estimated cost :cost.', [
                    'tokens' => number_format($estimate['tokens']),
                    'cost' => $estimate['cost'] ?? __('unknown'),
                ]));
            }
        }

        $children = $context->stage(JobStage::Discover, fn () => $documents->map(fn (Document $document) => $this->jobs->create(JobType::IndexDocument, [
            'parent_id' => $job->id,
            'collection_id' => $job->collection_id,
            'source_id' => $document->source_id,
            'document_id' => $document->id,
            'trigger' => 'reindex',
            'created_by' => $job->created_by,
            'payload' => ['document_version_id' => $document->latest_version_id, 'index_version_ids' => [$version->id]],
        ], false))->all());

        if ($children === []) {
            return;
        }

        $context->progress(0, count($children));
        $job->forceFill(['payload' => [...($job->payload ?? []), 'waiting' => true]])->save();
        $context->flush();

        foreach ($children as $child) {
            $this->jobs->dispatch($child);
        }
    }

    /**
     * Publish the version when the reindex succeeded without failures.
     */
    public function complete(RagJob $job): void
    {
        $version = $job->index_version_id !== null ? IndexVersion::query()->with('collection.connection')->find($job->index_version_id) : null;

        if ($version === null || $version->status !== IndexVersionStatus::Building) {
            return;
        }

        if ($job->status !== JobStatus::Succeeded || $job->error_type === 'partial') {
            $version->forceFill(['status' => $job->status === JobStatus::Cancelled ? IndexVersionStatus::Discarded : IndexVersionStatus::Failed])->save();
            $this->jobs->create(JobType::PurgeIndexVersion, ['collection_id' => $version->collection_id, 'index_version_id' => $version->id, 'trigger' => 'reindex', 'created_by' => $job->created_by]);

            return;
        }

        $previous = DB::transaction(function () use ($version) {
            $collection = Collection::query()->lockForUpdate()->findOrFail($version->collection_id);
            $previous = $collection->active_index_version_id !== null ? IndexVersion::query()->find($collection->active_index_version_id) : null;

            $previous?->forceFill(['status' => IndexVersionStatus::Superseded])->save();
            $version->forceFill(['status' => IndexVersionStatus::Active, 'published_at' => now()])->save();
            $collection->forceFill(['active_index_version_id' => $version->id])->save();

            return $previous;
        });

        $stats = $this->store->stats($version->collection->connection, $version->id);
        $version->forceFill($stats)->save();

        // Document counters follow the published version...
        foreach (Document::query()->where('collection_id', $version->collection_id)->alive()->whereNotNull('current_version_id')->get(['id']) as $document) {
            $chunks = $this->store->countDocumentChunks($version->collection->connection, $version->id, $document->id);
            Document::query()->whereKey($document->id)->update(['chunk_count' => $chunks]);
        }

        if ($previous !== null) {
            $this->jobs->create(JobType::PurgeIndexVersion, ['collection_id' => $version->collection_id, 'index_version_id' => $previous->id, 'trigger' => 'reindex', 'created_by' => $job->created_by]);
        }
    }
}
