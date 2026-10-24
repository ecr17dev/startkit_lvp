<?php

namespace Starter\Knowledge\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Starter\Knowledge\Enums\Capability;
use Starter\Knowledge\Enums\ConnectionKind;
use Starter\Knowledge\Enums\ConnectionStatus;

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

    /**
     * Laravel database connection holding the data plane.
     */
    public function databaseConnection(): string
    {
        return $this->kind === ConnectionKind::Internal
            ? (string) config('database.default')
            : 'rag_'.$this->id;
    }
}
