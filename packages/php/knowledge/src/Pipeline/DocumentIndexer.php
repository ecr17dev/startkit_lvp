<?php

namespace Starter\Knowledge\Pipeline;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Cache;
use Starter\Ai\Enums\Feature;
use Starter\Ai\Pricing\CostEstimator;
use Starter\Identity\Identity;
use Starter\Knowledge\Embeddings\Embedder;
use Starter\Knowledge\Enums\DocumentStatus;
use Starter\Knowledge\Enums\IndexVersionStatus;
use Starter\Knowledge\Enums\JobStage;
use Starter\Knowledge\Exceptions\JobPausedException;
use Starter\Knowledge\Exceptions\RetryLaterException;
use Starter\Knowledge\Ingestion\Chunker;
use Starter\Knowledge\Models\DocumentVersion;
use Starter\Knowledge\Models\IndexVersion;
use Starter\Knowledge\Models\RagJob;
use Starter\Knowledge\Store\VectorStore;

/**
 * Chunk → embed → persist → publish → verify of one document version in
 * each index version receiving writes. Embedding calls respect the
 * concurrency per AI connection and the confirmed ingestion budget.
 * Re-running it replaces the same chunks (idempotent).
 */
class DocumentIndexer
{
    public function __construct(
        protected Chunker $chunker,
        protected Embedder $embedder,
        protected VectorStore $store,
        protected IndexVersions $versions,
        protected IngestionBudget $budget,
        protected CostEstimator $costs,
    ) {}

    public function run(JobContext $context): void
    {
        $job = $context->job;
        $version = DocumentVersion::query()->with('document.source.collection.connection')->find((int) ($job->payload['document_version_id'] ?? 0));
        $document = $version?->document;

        // Deleted or superseded meanwhile: nothing to index...
        if ($version === null || $document === null || $document->isDeleted() || $document->latest_version_id !== $version->id) {
            $context->count('skipped');

            return;
        }

        $collection = $document->source->collection;
        $targets = IndexVersion::query()->with('profile.aiModel', 'profile.connection')
            ->whereIn('id', (array) ($job->payload['index_version_ids'] ?? []))
            ->whereIn('status', [IndexVersionStatus::Active->value, IndexVersionStatus::Building->value])
            ->get();

        if ($targets->isEmpty()) {
            $targets = collect($this->versions->targets($collection));
        }

        // A reindex only writes the building version: the document keeps serving...
        $writesActive = $targets->contains(fn (IndexVersion $target) => $target->status === IndexVersionStatus::Active);

        if ($writesActive) {
            $document->forceFill(['status' => DocumentStatus::Indexing])->save();
        }

        $owner = $job->parent_id !== null ? RagJob::query()->find($job->parent_id) : null;
        $acl = $document->effectiveAcl();
        $chunked = [];
        $activeChunks = null;

        foreach ($targets as $target) {
            $key = md5((string) json_encode($target->chunking));
            $chunks = $chunked[$key] ??= $context->stage(JobStage::Chunk, fn () => $this->chunks($version, $target));
            $profile = $target->profile;
            $texts = array_map(fn (array $chunk) => $this->embeddingText($document->title, $chunk), $chunks);
            $vectors = [];

            foreach (array_chunk($texts, max(1, $profile->batch_size)) as $batch) {
                $context->checkpoint();
                $vectors = [...$vectors, ...$context->stage(JobStage::Embed, fn () => $this->embedBatch($context, $owner, $target, $batch, $document->id))];
            }

            $count = $context->stage(JobStage::Persist, fn () => $this->store->replaceDocument($target, $document, $version, $chunks, $vectors, $acl, $collection->textSearchConfig()));

            $context->stage(JobStage::Verify, function () use ($collection, $target, $document, $count) {
                $stored = $this->store->countDocumentChunks($collection->connection, $target->id, $document->id);

                if ($stored !== $count) {
                    throw new \RuntimeException((string) __('Verification failed: :stored chunks stored, :expected expected.', ['stored' => $stored, 'expected' => $count]));
                }
            });

            $context->count('chunks', $count);

            if ($target->status === IndexVersionStatus::Active) {
                $activeChunks = $chunks;
            }
        }

        $context->stage(JobStage::Publish, function () use ($document, $version, $activeChunks) {
            if ($activeChunks === null) {
                return;
            }

            $document->forceFill([
                'status' => DocumentStatus::Indexed,
                'current_version_id' => $version->id,
                'chunk_count' => count($activeChunks),
                'token_count' => array_sum(array_column($activeChunks, 'token_count')),
                'indexed_at' => now(),
                'error' => null,
            ])->save();
        });

        $context->count('documents');
    }

    /**
     * @return list<array{content: string, token_count: int, metadata: array<string, mixed>}>
     */
    protected function chunks(DocumentVersion $version, IndexVersion $target): array
    {
        $document = $version->document;

        return array_map(fn (array $chunk) => [
            ...$chunk,
            'metadata' => array_filter([
                'title' => $document->title,
                'uri' => $document->uri,
                ...$chunk['metadata'],
                'estimator' => Chunker::TOKEN_ESTIMATOR,
            ], fn ($value) => $value !== null),
        ], $this->chunker->chunk($version->content, $target->chunking, $version->pages));
    }

    /**
     * The embedded text carries the title and section (the stored chunk
     * content does not), which helps short passages.
     *
     * @param  array{content: string, metadata: array<string, mixed>}  $chunk
     */
    protected function embeddingText(?string $title, array $chunk): string
    {
        return trim(implode("\n", array_filter([$title, $chunk['metadata']['section'] ?? null]))."\n\n".$chunk['content']);
    }

    /**
     * @param  list<string>  $batch
     * @return list<list<float>>
     */
    protected function embedBatch(JobContext $context, ?RagJob $owner, IndexVersion $target, array $batch, int $documentId): array
    {
        $profile = $target->profile;
        $model = $this->embedder->model($profile);
        $slots = max(1, (int) config('starter.rag.indexing.concurrency', 2));
        $lock = null;

        for ($slot = 0; $slot < $slots; $slot++) {
            $candidate = Cache::lock('rag-embed:'.$model->connection_id.':'.$slot, 300);

            if ($candidate->get()) {
                $lock = $candidate;

                break;
            }
        }

        if ($lock === null) {
            throw new RetryLaterException((string) __('Waiting for a free embedding slot of the AI connection.'), random_int(5, 20));
        }

        $budgetOwner = $owner ?? $context->job;
        $estimate = $this->costs->estimate($model, array_sum(array_map('mb_strlen', $batch)), 0)->amount;

        try {
            if (! $this->budget->reserve($budgetOwner, $estimate)) {
                throw new JobPausedException((string) __('The confirmed ingestion budget was reached. Raise it to continue.'));
            }

            try {
                $result = $this->embedder->embed($profile, $batch, Feature::Embedding, $this->actor($context->job), [
                    'rag_job_id' => $context->job->id,
                    'collection_id' => $target->collection_id,
                    'index_version_id' => $target->id,
                    'document_id' => $documentId,
                ]);
            } catch (\Throwable $exception) {
                $this->budget->settle($budgetOwner, $estimate, '0');

                throw $exception;
            }

            $cost = $result['request']->cost;
            $cost = $cost === null ? null : (string) $cost;

            $this->budget->settle($budgetOwner, $estimate, $cost);

            if ($budgetOwner->id !== $context->job->id) {
                $this->budget->charge($context->job, $cost);
            }

            $this->versions->addCost($target, $cost);
            $context->count('tokens', (int) $result['request']->input_tokens);
            $context->count('embedding_calls');

            return $result['vectors'];
        } finally {
            $lock->release();
        }
    }

    /**
     * The user who started the job (budgets per user apply to them).
     */
    protected function actor(RagJob $job): ?Authenticatable
    {
        if ($job->created_by === null) {
            return null;
        }

        $user = Identity::userModel()::query()->find($job->created_by);

        return $user instanceof Authenticatable ? $user : null;
    }
}
