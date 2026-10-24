<?php

namespace Starter\Knowledge\Store;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Starter\Knowledge\Models\Document;
use Starter\Knowledge\Models\DocumentVersion;
use Starter\Knowledge\Models\IndexVersion;
use Starter\Knowledge\Models\RagConnection;

/**
 * Writes and deletes on the data plane of a managed connection. A
 * document is replaced atomically per index version: its previous chunks
 * and vectors disappear in the same transaction the new ones appear, so
 * retries never duplicate a logical document.
 */
class VectorStore
{
    public function db(RagConnection $connection): ConnectionInterface
    {
        return DB::connection($connection->databaseConnection());
    }

    /**
     * @param  list<array{content: string, token_count: int, metadata: array<string, mixed>}>  $chunks
     * @param  list<list<float>>  $vectors  one per chunk
     * @param  list<string>  $acl
     */
    public function replaceDocument(IndexVersion $version, Document $document, DocumentVersion $documentVersion, array $chunks, array $vectors, array $acl, string $tsConfig): int
    {
        $collection = $version->collection;
        $profile = $version->profile;
        $db = $this->db($collection->connection);
        $table = $profile->table();

        return $db->transaction(function () use ($db, $version, $document, $documentVersion, $chunks, $vectors, $acl, $tsConfig, $table, $collection) {
            $db->delete('DELETE FROM rag_chunks WHERE index_version_id = ? AND document_id = ?', [$version->id, $document->id]);

            $aclLiteral = $this->textArray($acl);

            foreach (array_chunk($chunks, 100, true) as $batch) {
                $rows = [];
                $bindings = [];

                foreach ($batch as $ordinal => $chunk) {
                    $rows[] = '(?, ?, ?, ?, ?, ?, ?, ?, ?::jsonb, ?::text[], ?::regconfig)';
                    array_push(
                        $bindings,
                        $version->id, $collection->id, $document->source_id, $document->id, $documentVersion->id, $ordinal,
                        $chunk['content'], $chunk['token_count'], json_encode($chunk['metadata'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), $aclLiteral, $tsConfig,
                    );
                }

                $inserted = $db->select(
                    'INSERT INTO rag_chunks (index_version_id, collection_id, source_id, document_id, document_version_id, ordinal, content, token_count, metadata, acl, ts_config) VALUES '
                    .implode(', ', $rows).' RETURNING id, ordinal',
                    $bindings,
                );

                $vectorRows = [];
                $vectorBindings = [];

                foreach ($inserted as $row) {
                    $vectorRows[] = '(?, ?, ?, ?::vector)';
                    array_push($vectorBindings, $row->id, $version->id, $collection->id, $this->vectorLiteral($vectors[(int) $row->ordinal]));
                }

                if ($vectorRows !== []) {
                    $db->insert("INSERT INTO {$table} (chunk_id, index_version_id, collection_id, embedding) VALUES ".implode(', ', $vectorRows), $vectorBindings);
                }
            }

            return count($chunks);
        });
    }

    /**
     * Remove a document from every index version (its vectors cascade).
     */
    public function deleteDocument(RagConnection $connection, int $documentId): int
    {
        return $this->db($connection)->delete('DELETE FROM rag_chunks WHERE document_id = ?', [$documentId]);
    }

    /**
     * Update the access list of every chunk of a document (immediate).
     *
     * @param  list<string>  $acl
     */
    public function updateAcl(RagConnection $connection, int $documentId, array $acl): int
    {
        return $this->db($connection)->update('UPDATE rag_chunks SET acl = ?::text[] WHERE document_id = ?', [$this->textArray($acl), $documentId]);
    }

    /**
     * Update the access list of many documents at once.
     *
     * @param  array<int, int>  $documentIds
     * @param  list<string>  $acl
     */
    public function updateAclMany(RagConnection $connection, array $documentIds, array $acl): int
    {
        $updated = 0;

        foreach (array_chunk($documentIds, 500) as $ids) {
            $updated += $this->db($connection)->update('UPDATE rag_chunks SET acl = ?::text[] WHERE document_id = ANY(?::bigint[])', [$this->textArray($acl), '{'.implode(',', $ids).'}']);
        }

        return $updated;
    }

    /**
     * Delete the chunks of an index version in batches.
     */
    public function deleteIndexVersion(RagConnection $connection, int $indexVersionId, int $batch = 5000): int
    {
        $db = $this->db($connection);
        $total = 0;

        do {
            $deleted = $db->delete('DELETE FROM rag_chunks WHERE id IN (SELECT id FROM rag_chunks WHERE index_version_id = ? LIMIT ?)', [$indexVersionId, $batch]);
            $total += $deleted;
        } while ($deleted === $batch);

        return $total;
    }

    public function deleteCollection(RagConnection $connection, int $collectionId, int $batch = 5000): int
    {
        $db = $this->db($connection);
        $total = 0;

        do {
            $deleted = $db->delete('DELETE FROM rag_chunks WHERE id IN (SELECT id FROM rag_chunks WHERE collection_id = ? LIMIT ?)', [$collectionId, $batch]);
            $total += $deleted;
        } while ($deleted === $batch);

        return $total;
    }

    public function deleteSource(RagConnection $connection, int $sourceId): int
    {
        return $this->db($connection)->delete('DELETE FROM rag_chunks WHERE source_id = ?', [$sourceId]);
    }

    /**
     * @return array{chunks: int, tokens: int, documents: int}
     */
    public function stats(RagConnection $connection, int $indexVersionId): array
    {
        $row = $this->db($connection)->selectOne(
            'SELECT count(*) AS chunks, coalesce(sum(token_count), 0) AS tokens, count(DISTINCT document_id) AS documents FROM rag_chunks WHERE index_version_id = ?',
            [$indexVersionId],
        );

        return ['chunks' => (int) $row->chunks, 'tokens' => (int) $row->tokens, 'documents' => (int) $row->documents];
    }

    public function countDocumentChunks(RagConnection $connection, int $indexVersionId, int $documentId): int
    {
        return (int) $this->db($connection)->scalar('SELECT count(*) FROM rag_chunks WHERE index_version_id = ? AND document_id = ?', [$indexVersionId, $documentId]);
    }

    /**
     * Chunks of a document in an index version, in order.
     *
     * @return list<object{id: int, ordinal: int, content: string, token_count: int, metadata: string}>
     */
    public function documentChunks(RagConnection $connection, int $indexVersionId, int $documentId, int $limit = 500): array
    {
        /** @var list<object{id: int, ordinal: int, content: string, token_count: int, metadata: string}> */
        return $this->db($connection)->select(
            'SELECT id, ordinal, content, token_count, metadata FROM rag_chunks WHERE index_version_id = ? AND document_id = ? ORDER BY ordinal LIMIT ?',
            [$indexVersionId, $documentId, $limit],
        );
    }

    /**
     * @param  list<float>  $vector
     */
    public function vectorLiteral(array $vector): string
    {
        return '['.implode(',', array_map(fn (float|int $value) => rtrim(rtrim(sprintf('%.8F', $value), '0'), '.') ?: '0', $vector)).']';
    }

    /**
     * PostgreSQL text[] literal with every element quoted.
     *
     * @param  list<string>  $values
     */
    public function textArray(array $values): string
    {
        return '{'.implode(',', array_map(fn (string $value) => '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $value).'"', $values)).'}';
    }
}
