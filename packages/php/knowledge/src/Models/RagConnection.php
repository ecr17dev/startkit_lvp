<?php

namespace Starter\Knowledge\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Starter\Knowledge\Enums\Capability;
use Starter\Knowledge\Enums\ConnectionKind;
use Starter\Knowledge\Enums\ConnectionStatus;
use Starter\Knowledge\Store\ExternalDatabases;

/**
 * Where a collection's knowledge is stored and searched: the internal
 * PostgreSQL, an external PostgreSQL with pgvector or a retrieval API.
 *
 * @property int $id
 * @property string $name
 * @property string $label
 * @property ConnectionKind $kind
 * @property array<string, mixed>|null $config
 * @property array<string, string|null>|null $credentials
 * @property ConnectionStatus $status
 * @property list<string>|null $capabilities
 * @property array<string, mixed>|null $diagnostics
 * @property string|null $pgvector_version
 * @property bool $is_default
 * @property CarbonInterface|null $checked_at
 * @property CarbonInterface $created_at
 * @property CarbonInterface $updated_at
 */
class RagConnection extends Model
{
    protected $table = 'rag_connections';

    /**
     * @var list<string>
     */
    protected $guarded = ['id'];

    /**
     * Credentials never leave the server.
     *
     * @var list<string>
     */
    protected $hidden = ['credentials'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => ConnectionKind::class,
            'config' => 'array',
            'credentials' => 'encrypted:array',
            'status' => ConnectionStatus::class,
            'capabilities' => 'array',
            'diagnostics' => 'array',
            'is_default' => 'boolean',
            'checked_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<EmbeddingProfile, $this>
     */
    public function profiles(): HasMany
    {
        return $this->hasMany(EmbeddingProfile::class, 'connection_id');
    }

    /**
     * @return HasMany<Collection, $this>
     */
    public function collections(): HasMany
    {
        return $this->hasMany(Collection::class, 'connection_id');
    }

    public function isReady(): bool
    {
        return $this->status === ConnectionStatus::Ready;
    }

    public function can(Capability $capability): bool
    {
        return in_array($capability->value, $this->capabilities ?? [], true);
    }

    public function setting(string $key, mixed $default = null): mixed
    {
        return $this->config[$key] ?? $default;
    }

    /**
     * Read only connections only search: ingestion, deletions and
     * reindexing are managed by the system that owns the data.
     */
    public function isReadOnly(): bool
    {
        return $this->kind === ConnectionKind::Postgres && (bool) $this->setting('read_only', false);
    }

    /**
     * Capabilities of a managed connection once its diagnostics pass (a
     * retrieval API declares its own).
     *
     * @return list<string>
     */
    public function managedCapabilities(): array
    {
        if (! $this->isReadOnly()) {
            return Capability::all();
        }

        return [Capability::Query->value, Capability::ListCollections->value, Capability::ListDocuments->value, Capability::Metrics->value, Capability::Chunks->value];
    }

    /**
     * Laravel database connection holding the data plane (registered at
     * runtime for external PostgreSQL connections).
     */
    public function databaseConnection(): string
    {
        if ($this->kind === ConnectionKind::Api) {
            throw new \LogicException('A retrieval API connection has no database.');
        }

        return $this->kind === ConnectionKind::Internal
            ? (string) config('database.default')
            : app(ExternalDatabases::class)->register($this);
    }
}
