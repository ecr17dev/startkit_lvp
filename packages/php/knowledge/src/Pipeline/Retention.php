<?php

namespace Starter\Knowledge\Pipeline;

use Illuminate\Support\Facades\Storage;
use Starter\Knowledge\Models\Collection;
use Starter\Knowledge\Models\DocumentVersion;

/**
 * Retention policy of a collection: older document versions (neither the
 * searchable nor the latest one) are removed after the configured days,
 * with their stored originals. Without a policy every version is kept.
 */
class Retention
{
    public function purge(int $limit = 500): int
    {
        $purged = 0;

        foreach (Collection::query()->whereNotNull('retention_days')->where('status', Collection::ACTIVE)->get() as $collection) {
            $versions = DocumentVersion::query()
                ->join('rag_documents', 'rag_documents.id', '=', 'rag_document_versions.document_id')
                ->where('rag_documents.collection_id', $collection->id)
                ->whereColumn('rag_document_versions.id', '!=', 'rag_documents.current_version_id')
                ->where(fn ($query) => $query->whereNull('rag_documents.latest_version_id')->orWhereColumn('rag_document_versions.id', '!=', 'rag_documents.latest_version_id'))
                ->where('rag_document_versions.created_at', '<', now()->subDays((int) $collection->retention_days))
                ->limit($limit)
                ->get(['rag_document_versions.id', 'rag_document_versions.storage_disk', 'rag_document_versions.storage_path']);

            foreach ($versions as $version) {
                if ($version->storage_path !== null && ! DocumentVersion::query()->where('storage_path', $version->storage_path)->whereKeyNot($version->id)->exists()) {
                    Storage::disk($version->storage_disk ?? (string) config('starter.rag.storage_disk', 'local'))->delete($version->storage_path);
                }

                DocumentVersion::query()->whereKey($version->id)->delete();
                $purged++;
            }
        }

        return $purged;
    }
}
