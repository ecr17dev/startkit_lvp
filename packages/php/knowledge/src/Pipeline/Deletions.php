<?php

namespace Starter\Knowledge\Pipeline;

use Illuminate\Support\Facades\DB;
use Starter\Knowledge\Enums\DocumentStatus;
use Starter\Knowledge\Enums\JobType;
use Starter\Knowledge\Models\Document;
use Starter\Knowledge\Models\RagJob;
use Starter\Knowledge\Store\VectorStore;

/**
 * Deleting a document removes it from the queries immediately (its chunks
 * and vectors are deleted in the request) and a traceable purge job
 * removes the stored originals and versions afterwards. Retrieval keeps
 * no result cache, so nothing stale can be served.
 */
class Deletions
{
    public function __construct(protected VectorStore $store, protected RagJobs $jobs) {}

    public function delete(Document $document, string $reason, ?RagJob $parent = null, ?int $userId = null): RagJob
    {
        $collection = $document->collection;

        DB::transaction(function () use ($document, $reason) {
            $document->forceFill([
                'status' => DocumentStatus::Deleted,
                'deleted_at' => now(),
                'chunk_count' => 0,
                'metadata' => [...($document->metadata ?? []), 'deleted_reason' => $reason],
            ])->save();
        });

        $this->store->deleteDocument($collection->connection, $document->id);

        return $this->jobs->create(JobType::PurgeDocument, [
            'collection_id' => $document->collection_id,
            'source_id' => $document->source_id,
            'document_id' => $document->id,
            'trigger' => $parent !== null ? 'sync' : 'manual',
            'created_by' => $userId ?? $parent?->created_by,
            'payload' => ['reason' => $reason, 'parent_job_id' => $parent?->id],
        ]);
    }
}
