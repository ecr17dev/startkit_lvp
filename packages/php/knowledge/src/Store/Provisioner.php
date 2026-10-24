<?php

namespace Starter\Knowledge\Store;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Starter\Knowledge\Models\EmbeddingProfile;
use Starter\Knowledge\Models\RagConnection;
use Throwable;

/**
 * Diagnoses a managed RAG connection and provisions its data plane
 * idempotently: the chunks table (full text + access lists) and one vector
 * table per embedding profile with an index chosen for its dimensions and
 * the installed pgvector. Nothing is created unless the diagnostics allow
 * it, and the extension is only created when explicitly authorized.
 */
class Provisioner
{
    public const MIN_POSTGRES = 130000;

    /**
     * Largest dimensions an HNSW index accepts on `vector` (and on
     * `halfvec`, from pgvector 0.7). Larger profiles use exact search.
     */
    public const HNSW_MAX_DIMENSIONS = 2000;

    public const HNSW_HALFVEC_MAX_DIMENSIONS = 4000;

    public function db(RagConnection $connection): ConnectionInterface
    {
        return DB::connection($connection->databaseConnection());
    }

    /**
     * Run every check, optionally creating the extension and the schema.
     */
    public function diagnose(RagConnection $connection, bool $createExtension = false, bool $provision = false): DiagnosticReport
    {
        $report = new DiagnosticReport;

        try {
            $db = $this->db($connection);
            $version = (int) $db->scalar('SHOW server_version_num');
            $report->ok('connect', 'Connectivity', __('Connected to PostgreSQL :version.', ['version' => (string) $db->scalar('SHOW server_version')]));
        } catch (Throwable $exception) {
            return $report->error('connect', 'Connectivity', $this->sanitize($exception));
        }

        $this->checkTls($db, $connection, $report);

        if ($version < self::MIN_POSTGRES) {
            return $report->error('version', 'PostgreSQL version', __('PostgreSQL 13 or later is required.'));
        }

        $report->ok('version', 'PostgreSQL version', (string) $version);

        if (! $this->checkExtension($db, $report, $createExtension)) {
            return $report;
        }

        $this->checkPrivileges($db, $report);

        if ($provision) {
            try {
                $this->provisionChunks($connection);
                $report->ok('schema', 'Schema', __('Knowledge tables and indexes are ready.'));
            } catch (Throwable $exception) {
                return $report->error('schema', 'Schema', $this->sanitize($exception), $this->chunksSql());
            }
        } elseif ($this->hasChunksTable($db)) {
            $report->ok('schema', 'Schema', __('Knowledge tables found.'));
        } else {
            $report->skipped('schema', 'Schema', __('The tables are created on activation.'));
        }

        if ($this->hasChunksTable($db)) {
            $this->smokeTest($db, $report);
        }

        return $report;
    }

    protected function checkTls(ConnectionInterface $db, RagConnection $connection, DiagnosticReport $report): void
    {
        try {
            $ssl = $db->scalar('SELECT ssl FROM pg_stat_ssl WHERE pid = pg_backend_pid()');
        } catch (Throwable) {
            $ssl = null;
        }

        if ($ssl) {
            $report->ok('tls', 'TLS', __('The connection is encrypted.'));
        } elseif ($connection->kind->value === 'internal' || ! app()->isProduction()) {
            $report->warning('tls', 'TLS', __('The connection is not encrypted (acceptable for a local or private network).'));
        } else {
            $report->error('tls', 'TLS', __('External connections require TLS in production (sslmode=require or stricter).'));
        }
    }

    protected function checkExtension(ConnectionInterface $db, DiagnosticReport $report, bool $create): bool
    {
        $sql = 'CREATE EXTENSION IF NOT EXISTS vector;';
        $available = $db->selectOne("SELECT default_version, installed_version FROM pg_available_extensions WHERE name = 'vector'");

        if ($available === null) {
            $report->error('pgvector', 'pgvector', __('pgvector is not installed on the PostgreSQL server. Ask the database administrator to install it (for example the pgvector/pgvector image) and run:'), $sql);

            return false;
        }

        if ($available->installed_version === null && $create) {
            try {
                $db->statement($sql);
                $available = $db->selectOne("SELECT default_version, installed_version FROM pg_available_extensions WHERE name = 'vector'");
            } catch (Throwable $exception) {
                $report->error('pgvector', 'pgvector', __('The extension could not be created with this role (:error). Ask the database administrator to run:', ['error' => $this->sanitize($exception)]), $sql);

                return false;
            }
        }

        if ($available?->installed_version === null) {
            $report->error('pgvector', 'pgvector', __('pgvector is available but not enabled in this database. Authorize its creation or ask the database administrator to run:'), $sql);

            return false;
        }

        $report->setPgvector((string) $available->installed_version);
        $report->ok('pgvector', 'pgvector', __('pgvector :version enabled.', ['version' => (string) $available->installed_version]));

        if (version_compare((string) $available->installed_version, '0.5.0', '<')) {
            $report->warning('pgvector_index', 'Vector indexes', __('pgvector older than 0.5 has no HNSW indexes: searches will be exact.'));
        }

        return true;
    }

    protected function checkPrivileges(ConnectionInterface $db, DiagnosticReport $report): void
    {
        $canCreate = (bool) $db->scalar("SELECT has_schema_privilege(current_user, current_schema(), 'CREATE')");

        if ($canCreate) {
            $report->ok('privileges', 'Privileges', __('The role can create the knowledge tables (no superuser needed).'));
        } elseif ($this->hasChunksTable($db)) {
            $report->warning('privileges', 'Privileges', __('The role cannot create tables; the existing ones will be used. New embedding profiles need an administrator.'));
        } else {
            $report->error('privileges', 'Privileges', __('The role cannot create tables in the schema. Grant CREATE or run the schema SQL with a migration role:'), $this->chunksSql());
        }
    }

    /**
     * Insert and search in an isolated diagnostic set, then roll back.
     */
    protected function smokeTest(ConnectionInterface $db, DiagnosticReport $report): void
    {
        try {
            $db->beginTransaction();

            $db->statement('CREATE TEMPORARY TABLE rag_diagnostic_vectors (id int, embedding vector(3)) ON COMMIT DROP');
            $db->insert("INSERT INTO rag_diagnostic_vectors VALUES (1, '[1,0,0]'), (2, '[0,1,0]')");
            $nearest = $db->scalar("SELECT id FROM rag_diagnostic_vectors ORDER BY embedding <=> '[0.9,0.1,0]' LIMIT 1");

            $db->insert("INSERT INTO rag_chunks (index_version_id, collection_id, source_id, document_id, document_version_id, ordinal, content, token_count, metadata, acl, ts_config) VALUES (0, 0, 0, 0, 0, 0, 'diagnostic knowledge probe', 3, '{}', '{diagnostic}', 'simple')");
            $found = $db->scalar("SELECT count(*) FROM rag_chunks WHERE collection_id = 0 AND search @@ websearch_to_tsquery('simple', 'knowledge probe') AND acl && ARRAY['diagnostic']::text[]");

            $db->rollBack();

            if ((int) $nearest === 1 && (int) $found === 1) {
                $report->ok('smoke', 'Insert and search test', __('Vector and full text search answered correctly (test data removed).'));
            } else {
                $report->error('smoke', 'Insert and search test', __('The test search returned unexpected results.'));
            }
        } catch (Throwable $exception) {
            if ($db->transactionLevel() > 0) {
                $db->rollBack();
            }

            $report->error('smoke', 'Insert and search test', $this->sanitize($exception));
        }
    }

    public function hasChunksTable(ConnectionInterface $db): bool
    {
        return (bool) $db->scalar("SELECT to_regclass('rag_chunks') IS NOT NULL");
    }

    /**
     * Shared chunks table: no foreign keys to the control plane (it may
     * live in another database), denormalized ids for the filters.
     */
    public function chunksSql(): string
    {
        return <<<'SQL'
CREATE TABLE IF NOT EXISTS rag_chunks (
    id bigserial PRIMARY KEY,
    index_version_id bigint NOT NULL,
    collection_id bigint NOT NULL,
    source_id bigint NOT NULL,
    document_id bigint NOT NULL,
    document_version_id bigint NOT NULL,
    ordinal integer NOT NULL,
    content text NOT NULL,
    token_count integer NOT NULL DEFAULT 0,
    metadata jsonb NOT NULL DEFAULT '{}',
    acl text[] NOT NULL DEFAULT '{}',
    ts_config regconfig NOT NULL DEFAULT 'simple',
    search tsvector GENERATED ALWAYS AS (to_tsvector(ts_config, content)) STORED,
    created_at timestamptz NOT NULL DEFAULT now()
);
CREATE UNIQUE INDEX IF NOT EXISTS rag_chunks_unique ON rag_chunks (index_version_id, document_id, ordinal);
CREATE INDEX IF NOT EXISTS rag_chunks_collection ON rag_chunks (collection_id, index_version_id);
CREATE INDEX IF NOT EXISTS rag_chunks_document ON rag_chunks (document_id);
CREATE INDEX IF NOT EXISTS rag_chunks_search ON rag_chunks USING gin (search);
CREATE INDEX IF NOT EXISTS rag_chunks_acl ON rag_chunks USING gin (acl);
SQL;
    }

    public function provisionChunks(RagConnection $connection): void
    {
        $db = $this->db($connection);

        foreach (array_filter(array_map('trim', explode(';', $this->chunksSql()))) as $statement) {
            $db->statement($statement);
        }
    }

    /**
     * Index strategy for the profile dimensions and pgvector version.
     *
     * @return array{index: string|null, exact: bool}
     */
    public function indexStrategy(int $dimensions, ?string $pgvector): array
    {
        $pgvector ??= '0.0.0';

        if (version_compare($pgvector, '0.5.0', '>=') && $dimensions <= self::HNSW_MAX_DIMENSIONS) {
            return ['index' => 'hnsw', 'exact' => false];
        }

        if (version_compare($pgvector, '0.7.0', '>=') && $dimensions <= self::HNSW_HALFVEC_MAX_DIMENSIONS) {
            return ['index' => 'hnsw_halfvec', 'exact' => false];
        }

        // Never truncate vectors to fit an index: search exactly...
        return ['index' => null, 'exact' => true];
    }

    /**
     * Create the vector table of a profile (idempotent) and record its
     * storage decisions on the profile.
     */
    public function provisionProfile(EmbeddingProfile $profile): void
    {
        $connection = $profile->connection;
        $db = $this->db($connection);
        $table = $profile->table();
        $dimensions = $profile->dimensions;
        $strategy = $this->indexStrategy($dimensions, $connection->pgvector_version);
        $opclass = $profile->metric->operatorClass();

        $db->statement("CREATE TABLE IF NOT EXISTS {$table} (
            chunk_id bigint PRIMARY KEY REFERENCES rag_chunks (id) ON DELETE CASCADE,
            index_version_id bigint NOT NULL,
            collection_id bigint NOT NULL,
            embedding vector({$dimensions}) NOT NULL
        )");
        $db->statement("CREATE INDEX IF NOT EXISTS {$table}_version ON {$table} (collection_id, index_version_id)");

        if ($strategy['index'] === 'hnsw') {
            $db->statement("CREATE INDEX IF NOT EXISTS {$table}_hnsw ON {$table} USING hnsw (embedding {$opclass})");
        } elseif ($strategy['index'] === 'hnsw_halfvec') {
            $halfOpclass = str_replace('vector_', 'halfvec_', $opclass);
            $db->statement("CREATE INDEX IF NOT EXISTS {$table}_hnsw ON {$table} USING hnsw ((embedding::halfvec({$dimensions})) {$halfOpclass})");
        }

        $profile->forceFill([
            'storage' => ['table' => $table, 'index' => $strategy['index'], 'exact' => $strategy['exact'], 'pgvector' => $connection->pgvector_version],
            'status' => EmbeddingProfile::READY,
            'error' => null,
        ])->save();
    }

    /**
     * Drop the vector table of a profile no index version uses any more.
     */
    public function dropProfile(EmbeddingProfile $profile): void
    {
        $this->db($profile->connection)->statement('DROP TABLE IF EXISTS '.$profile->table());
    }

    public function sanitize(Throwable $exception): string
    {
        // Keep the SQLSTATE message, never the connection string or credentials...
        $message = preg_replace('/\(Connection: .*$/s', '', $exception->getMessage()) ?? '';
        $message = preg_replace('/(password|user|host)=\S+/i', '$1=***', $message) ?? '';

        return mb_substr(trim($message), 0, 500);
    }
}
