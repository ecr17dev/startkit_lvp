<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;
use Starter\Ai\Models\AiModel;
use Starter\Knowledge\Enums\JobStatus;
use Starter\Knowledge\Ingestion\Sources\AdapterRegistry;
use Starter\Knowledge\Knowledge;
use Starter\Knowledge\Models\Collection;
use Starter\Knowledge\Models\Document;
use Starter\Knowledge\Models\EmbeddingProfile;
use Starter\Knowledge\Models\IndexVersion;
use Starter\Knowledge\Models\RagConnection;
use Starter\Knowledge\Models\RagJob;
use Starter\Knowledge\Models\Source;

/**
 * Shapes knowledge records for the panel and the API. Never includes
 * credentials; document content is only added by the callers that
 * checked the access lists.
 */
class KnowledgePresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function status(): array
    {
        $knowledge = app(Knowledge::class);
        $connection = $knowledge->connection();

        return [
            'feature' => $knowledge->featureEnabled(),
            'mode' => $knowledge->mode()->value,
            'available' => $knowledge->available(),
            'connection' => $connection !== null ? self::connection($connection) : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function connection(RagConnection $connection): array
    {
        return [
            'id' => $connection->id,
            'name' => $connection->name,
            'label' => $connection->label,
            'kind' => $connection->kind->value,
            'status' => $connection->status->value,
            'capabilities' => $connection->capabilities ?? [],
            'pgvector_version' => $connection->pgvector_version,
            'diagnostics' => $connection->diagnostics,
            'checked_at' => $connection->checked_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function profile(EmbeddingProfile $profile): array
    {
        return [
            'id' => $profile->id,
            'name' => $profile->name,
            'model_id' => $profile->model_id,
            'provider' => $profile->provider,
            'model' => $profile->model,
            'dimensions' => $profile->dimensions,
            'metric' => $profile->metric->value,
            'normalize' => $profile->normalize,
            'batch_size' => $profile->batch_size,
            'max_input_tokens' => $profile->max_input_tokens,
            'status' => $profile->status,
            'error' => $profile->error,
            'storage' => $profile->storage,
            'simulated' => str_starts_with($profile->model, 'simulated-'),
            'collections' => Collection::query()->where('embedding_profile_id', $profile->id)->count(),
        ];
    }

    /**
     * Enabled models with the embeddings function.
     *
     * @return list<array{id: int, label: string, connection: string, simulated: bool}>
     */
    public static function embeddingModels(): array
    {
        return array_values(AiModel::query()->with('connection')->usable()->forFunction('embeddings')->orderBy('model')->get()
            ->map(fn (AiModel $model) => [
                'id' => $model->id,
                'label' => $model->displayName(),
                'connection' => $model->connection->label,
                'simulated' => $model->connection->driver->isSimulated(),
            ])->all());
    }

    /**
     * @return array<string, mixed>
     */
    public static function collection(Collection $collection): array
    {
        $active = $collection->activeVersion;
        $building = $collection->buildingVersion();
        $counts = $collection->documents()->alive()->selectRaw('status, count(*) AS total')->groupBy('status')->pluck('total', 'status')->map(fn ($total) => (int) $total);

        return [
            'id' => $collection->id,
            'slug' => $collection->slug,
            'name' => $collection->name,
            'description' => $collection->description,
            'status' => $collection->status,
            'language' => $collection->language,
            'embedding_profile_id' => $collection->embedding_profile_id,
            'profile' => $collection->profile->name,
            'chunking' => $collection->chunking,
            'search' => $collection->search,
            'acl' => $collection->acl,
            'retention_days' => $collection->retention_days,
            'sources' => $collection->sources()->count(),
            'documents' => (int) $counts->sum(),
            'documents_by_status' => $counts->all(),
            'active_version' => $active !== null ? self::indexVersion($active) : null,
            'building_version' => $building !== null ? self::indexVersion($building) : null,
            'needs_reindex' => $active !== null && ($active->embedding_profile_id !== $collection->embedding_profile_id || $active->chunking != $collection->chunking),
            'open_jobs' => $collection->jobs()->whereNull('parent_id')->whereIn('status', JobStatus::open())->count(),
            'created_at' => $collection->created_at->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function indexVersion(IndexVersion $version): array
    {
        return [
            'id' => $version->id,
            'number' => $version->number,
            'status' => $version->status->value,
            'reason' => $version->reason,
            'profile' => $version->profile->name,
            'embedding_profile_id' => $version->embedding_profile_id,
            'chunking' => $version->chunking,
            'documents' => $version->documents,
            'chunks' => $version->chunks,
            'tokens' => $version->tokens,
            'cost' => $version->cost_known ? (string) $version->cost : null,
            'published_at' => $version->published_at?->toIso8601String(),
            'purged_at' => $version->purged_at?->toIso8601String(),
            'created_at' => $version->created_at->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function source(Source $source): array
    {
        $stats = $source->documents()->alive()->selectRaw('count(*) AS documents, coalesce(sum(chunk_count), 0) AS chunks, coalesce(sum(size), 0) AS size, count(*) FILTER (WHERE status = ?) AS failed', ['failed'])->first();
        $cost = RagJob::query()->where('source_id', $source->id)->whereNull('parent_id')->selectRaw('coalesce(sum(cost), 0) AS cost, bool_and(cost_known) AS known')->first();
        $lastJob = $source->last_job_id !== null ? RagJob::query()->find($source->last_job_id) : null;
        $openJob = $source->jobs()->whereNull('parent_id')->whereIn('status', JobStatus::open())->latest()->first();

        return [
            'id' => $source->id,
            'collection_id' => $source->collection_id,
            'type' => $source->type->value,
            'name' => $source->name,
            'config' => self::sourceConfig($source),
            'acl' => $source->acl,
            'status' => $source->status->value,
            'schedule' => $source->schedule,
            'next_sync_at' => $source->next_sync_at?->toIso8601String(),
            'last_sync_at' => $source->last_sync_at?->toIso8601String(),
            'last_error' => $source->last_error,
            'documents' => (int) ($stats->documents ?? 0),
            'chunks' => (int) ($stats->chunks ?? 0),
            'size' => (int) ($stats->size ?? 0),
            'failed' => (int) ($stats->failed ?? 0),
            'cost' => ($cost->known ?? true) === false ? null : (string) ($cost->cost ?? '0'),
            'last_job' => $lastJob !== null ? self::job($lastJob) : null,
            'open_job' => $openJob !== null ? self::job($openJob) : null,
        ];
    }

    /**
     * Configuration without storage paths.
     *
     * @return array<string, mixed>
     */
    public static function sourceConfig(Source $source): array
    {
        $config = $source->config;

        if (isset($config['files'])) {
            $config['files'] = array_map(fn (array $file) => [
                'name' => $file['name'],
                'size' => $file['size'] ?? null,
                'mime' => $file['mime'] ?? null,
                'uploaded_at' => $file['uploaded_at'] ?? null,
            ], (array) $config['files']);
        }

        return $config;
    }

    /**
     * @return array<string, mixed>
     */
    public static function document(Document $document): array
    {
        return [
            'id' => $document->id,
            'collection_id' => $document->collection_id,
            'source_id' => $document->source_id,
            'source' => $document->relationLoaded('source') ? $document->source->name : null,
            'title' => $document->title,
            'uri' => $document->uri,
            'external_id' => $document->external_id,
            'mime_type' => $document->mime_type,
            'status' => $document->status->value,
            'content_hash' => $document->content_hash,
            'size' => $document->size,
            'chunk_count' => $document->chunk_count,
            'token_count' => $document->token_count,
            'acl' => $document->acl,
            'error' => $document->error,
            'indexed_at' => $document->indexed_at?->toIso8601String(),
            'updated_at' => $document->updated_at->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function job(RagJob $job): array
    {
        return [
            'id' => $job->id,
            'parent_id' => $job->parent_id,
            'type' => $job->type->value,
            'status' => $job->status->value,
            'stage' => $job->stage?->value,
            'progress_done' => $job->progress_done,
            'progress_total' => $job->progress_total,
            'attempts' => $job->attempts,
            'units' => $job->units ?? (object) [],
            'timings' => $job->timings ?? (object) [],
            'estimate' => $job->estimate,
            'cost' => $job->cost_known ? (string) $job->cost : null,
            'max_cost' => $job->max_cost !== null ? (string) $job->max_cost : null,
            'error' => $job->error,
            'error_type' => $job->error_type,
            'trigger' => $job->trigger,
            'warnings' => $job->payload['warnings'] ?? [],
            'source_id' => $job->source_id,
            'document_id' => $job->document_id,
            'children' => $job->parent_id === null ? $job->children()->count() : 0,
            'started_at' => $job->started_at?->toIso8601String(),
            'finished_at' => $job->finished_at?->toIso8601String(),
            'created_at' => $job->created_at->toIso8601String(),
        ];
    }

    /**
     * @param  Builder<RagJob>  $query
     * @return list<array<string, mixed>>
     */
    public static function jobs(Builder $query, int $limit = 30): array
    {
        return array_values($query->latest()->limit($limit)->get()->map(fn (RagJob $job) => self::job($job))->all());
    }

    /**
     * @return array<string, mixed>
     */
    public static function options(): array
    {
        return [
            'adapters' => app(AdapterRegistry::class)->options(),
            'disks' => array_values((array) config('starter.rag.disks', [])),
            'languages' => array_keys(Collection::LANGUAGES),
            'schedules' => Source::SCHEDULES,
            'limits' => [
                'max_file_mb' => (int) config('starter.rag.limits.max_file_mb', 20),
                'max_files' => (int) config('starter.rag.limits.max_files', 20),
                'max_pages' => (int) config('starter.rag.fetch.max_pages', 200),
                'max_depth' => (int) config('starter.rag.fetch.max_depth', 3),
            ],
            'automatic' => (bool) config('starter.rag.indexing.automatic'),
        ];
    }
}
