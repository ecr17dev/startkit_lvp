<?php

namespace Starter\Knowledge\Pipeline;

use Starter\Knowledge\Enums\DocumentStatus;
use Starter\Knowledge\Enums\JobStage;
use Starter\Knowledge\Enums\JobStatus;
use Starter\Knowledge\Enums\JobType;
use Starter\Knowledge\Exceptions\JobPausedException;
use Starter\Knowledge\Ingestion\Sources\SourceDrivers;
use Starter\Knowledge\Ingestion\Sources\SyncContext;
use Starter\Knowledge\Models\Document;
use Starter\Knowledge\Models\DocumentVersion;
use Starter\Knowledge\Models\RagJob;

/**
 * Sync of a source: discover → read → validate → extract → normalize →
 * deduplicate (DocumentIngestor), detect deletions, estimate the
 * embedding volume and cost, and hand every new version to an
 * index_document child job. Large ingestions pause until a budget is
 * confirmed.
 */
class SourceSync
{
    public function __construct(
        protected SourceDrivers $drivers,
        protected DocumentIngestor $ingestor,
        protected IndexVersions $versions,
        protected Estimator $estimator,
        protected RagJobs $jobs,
        protected Deletions $deletions,
    ) {}

    public function run(JobContext $context): void
    {
        $job = $context->job;
        $source = $job->source ?? throw new \RuntimeException((string) __('The source no longer exists.'));
        $collection = $source->collection;

        // Resumed after the budget confirmation: the versions are ready...
        if (($job->payload['pending_versions'] ?? null) !== null) {
            $this->dispatchChildren($context, array_map('intval', $job->payload['pending_versions']));

            return;
        }

        $this->versions->ensureActive($collection);
        $startedAt = $job->started_at ?? now();
        $force = (bool) ($job->payload['force'] ?? false);
        $sync = new SyncContext(fn () => $context->checkpoint());
        $changed = [];
        $seen = 0;

        $items = $context->stage(JobStage::Discover, fn () => $this->drivers->for($source->type)->discover($source, $sync));

        foreach ($items as $item) {
            $seen++;
            $context->progress($seen);
            $outcome = $this->ingestor->ingest($source, $item, $context, $force);
            $context->count($outcome->status);

            if ($outcome->status === IngestOutcome::CHANGED && $outcome->version !== null) {
                $changed[] = $outcome->version->id;
            } elseif ($outcome->status === IngestOutcome::GONE) {
                $this->deletions->delete($outcome->document, 'gone', $job);
                $context->count('deleted');
            }

            $context->checkpoint();
        }

        $context->progress($seen, $seen);

        // Documents missing from a complete discovery were deleted at the origin...
        if ($source->type->detectsDeletions() && ! $sync->truncated) {
            $missing = Document::query()->where('source_id', $source->id)->alive()
                ->where(fn ($query) => $query->whereNull('seen_at')->orWhere('seen_at', '<', $startedAt))
                ->get();

            foreach ($missing as $document) {
                $this->deletions->delete($document, 'missing', $job);
                $context->count('deleted');
            }
        }

        $job->forceFill(['payload' => [...($job->payload ?? []), 'warnings' => $sync->warnings]]);
        $this->dispatchChildren($context, $changed);
    }

    /**
     * @param  array<int, int>  $versionIds
     */
    protected function dispatchChildren(JobContext $context, array $versionIds): void
    {
        $job = $context->job;
        $collection = $job->source->collection;

        if ($versionIds === []) {
            return;
        }

        $targets = $this->versions->targets($collection);

        if ($job->max_cost === null && ! ($job->payload['confirmed'] ?? false)) {
            $characters = DocumentVersion::query()->whereIn('id', $versionIds)->selectRaw('char_length(content) AS length')->pluck('length')->map(fn ($length) => (int) $length)->all();
            $estimate = $this->estimator->estimate($characters, $targets);

            $job->forceFill(['estimate' => $estimate]);

            if ($estimate['needs_confirmation']) {
                $job->forceFill(['payload' => [...($job->payload ?? []), 'pending_versions' => $versionIds]]);
                $context->stage(JobStage::Embed, fn () => null);

                throw new JobPausedException((string) __('The ingestion needs a confirmed budget: about :tokens tokens, estimated cost :cost.', [
                    'tokens' => number_format($estimate['tokens']),
                    'cost' => $estimate['cost'] ?? __('unknown'),
                ]));
            }
        }

        Document::query()->whereIn('latest_version_id', $versionIds)->update(['status' => DocumentStatus::Indexing->value]);

        $children = [];

        foreach (DocumentVersion::query()->whereIn('id', $versionIds)->get(['id', 'document_id']) as $version) {
            $children[] = $this->jobs->create(JobType::IndexDocument, [
                'parent_id' => $job->id,
                'collection_id' => $job->collection_id,
                'source_id' => $job->source_id,
                'document_id' => $version->document_id,
                'trigger' => $job->trigger,
                'created_by' => $job->created_by,
                'payload' => ['document_version_id' => $version->id, 'index_version_ids' => array_map(fn ($target) => $target->id, $targets)],
            ], false);
        }

        $context->stage(JobStage::Embed, fn () => null);
        $context->progress(0, count($children));
        $job->forceFill(['payload' => [...array_diff_key($job->payload ?? [], ['pending_versions' => true]), 'waiting' => true]])->save();
        $context->flush();

        foreach ($children as $child) {
            $this->jobs->dispatch($child);
        }
    }

    /**
     * @return array{documents: int, open: int}
     */
    public static function childSummary(RagJob $job): array
    {
        return [
            'documents' => $job->children()->count(),
            'open' => $job->children()->whereIn('status', JobStatus::open())->count(),
        ];
    }
}
