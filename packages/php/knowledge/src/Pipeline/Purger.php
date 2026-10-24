<?php

namespace Starter\Knowledge\Pipeline;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Starter\Knowledge\Enums\IndexVersionStatus;
use Starter\Knowledge\Enums\JobStage;
use Starter\Knowledge\Enums\JobType;
use Starter\Knowledge\Models\Collection;
use Starter\Knowledge\Models\Document;
use Starter\Knowledge\Models\IndexVersion;
use Starter\Knowledge\Models\Source;
use Starter\Knowledge\Store\VectorStore;

/**
 * Traceable purges: originals and versions of deleted documents, chunks of
 * superseded index versions, and whole sources or collections after a
 * confirmed deletion.
 */
class Purger
{
    public function __construct(protected VectorStore $store) {}

    public function run(JobContext $context): void
    {
        $job = $context->job;

        $context->stage(JobStage::Purge, function () use ($job, $context) {
            match ($job->type) {
                JobType::PurgeDocument => $this->document($job->document_id, $context),
                JobType::PurgeIndexVersion => $this->indexVersion($job->index_version_id, $context),
                JobType::PurgeSource => $this->source((int) ($job->payload['source_id'] ?? $job->source_id), $context),
                JobType::PurgeCollection => $this->collection((int) ($job->payload['collection_id'] ?? $job->collection_id), $context),
                default => null,
            };
        });
    }

    protected function document(?int $id, JobContext $context): void
    {
        $document = $id !== null ? Document::query()->with('collection.connection')->find($id) : null;

        if ($document === null || ! $document->isDeleted()) {
            return;
        }

        // Vectors were removed at deletion; repeat in case it raced a writer...
        $context->count('chunks', $this->store->deleteDocument($document->collection->connection, $document->id));
        $context->count('files', $this->deleteFiles($document));

        DB::transaction(function () use ($document) {
            $document->forceFill(['current_version_id' => null, 'latest_version_id' => null])->save();
            $document->versions()->delete();
        });
    }

    protected function indexVersion(?int $id, JobContext $context): void
    {
        $version = $id !== null ? IndexVersion::query()->with('collection.connection')->find($id) : null;

        if ($version === null || in_array($version->status, [IndexVersionStatus::Active, IndexVersionStatus::Building], true)) {
            return;
        }

        $context->count('chunks', $this->store->deleteIndexVersion($version->collection->connection, $version->id));
        $version->forceFill(['purged_at' => now(), 'chunks' => 0])->save();
    }

    protected function source(int $id, JobContext $context): void
    {
        $source = Source::query()->with('collection.connection')->find($id);

        if ($source === null) {
            return;
        }

        $context->count('chunks', $this->store->deleteSource($source->collection->connection, $source->id));

        foreach ($source->documents()->get() as $document) {
            $context->count('files', $this->deleteFiles($document));
            $context->checkpoint();
        }

        Storage::disk((string) config('starter.rag.storage_disk', 'local'))->deleteDirectory("rag/{$source->collection_id}/{$source->id}");
        $source->delete();
    }

    protected function collection(int $id, JobContext $context): void
    {
        $collection = Collection::query()->with('connection')->find($id);

        if ($collection === null) {
            return;
        }

        $context->count('chunks', $this->store->deleteCollection($collection->connection, $collection->id));
        Storage::disk((string) config('starter.rag.storage_disk', 'local'))->deleteDirectory("rag/{$collection->id}");

        // Jobs of the collection cascade with it, this one included...
        DB::transaction(function () use ($collection, $context) {
            $context->job->forceFill(['collection_id' => null, 'payload' => [...($context->job->payload ?? []), 'collection_name' => $collection->name]])->save();
            $collection->forceFill(['active_index_version_id' => null])->save();
            $collection->delete();
        });
    }

    protected function deleteFiles(Document $document): int
    {
        $deleted = 0;

        foreach ($document->versions()->whereNotNull('storage_path')->get(['storage_disk', 'storage_path']) as $version) {
            $disk = Storage::disk($version->storage_disk ?? (string) config('starter.rag.storage_disk', 'local'));

            if ($version->storage_path !== null && $disk->exists($version->storage_path)) {
                $disk->delete($version->storage_path);
                $deleted++;
            }
        }

        return $deleted;
    }
}
