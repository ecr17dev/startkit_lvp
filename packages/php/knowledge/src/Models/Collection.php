<?php

namespace Starter\Knowledge\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Starter\Knowledge\Enums\ConnectionKind;
use Starter\Knowledge\Support\Principals;

/**
 * A group of knowledge with its own access, language, embedding profile,
 * chunking and search settings. A collection of a retrieval API points to
 * a remote collection (external_id) and has no profile: the remote service
 * indexes and ranks, the access list still applies locally.
 *
 * @property int $id
 * @property string $slug
 * @property string $name
 * @property string|null $description
 * @property int $connection_id
 * @property string|null $external_id
 * @property int|null $embedding_profile_id
 * @property int|null $active_index_version_id
 * @property string $language
 * @property array{target_tokens: int, overlap_tokens: int, max_tokens: int} $chunking
 * @property array{method: string, top_k: int, min_score: float|null, min_text_rank: float|null, max_context_tokens: int} $search
 * @property list<string> $acl
 * @property int|null $retention_days
 * @property string $status
 * @property int|null $created_by
 * @property CarbonInterface $created_at
 * @property CarbonInterface $updated_at
 * @property-read RagConnection $connection
 * @property-read EmbeddingProfile|null $profile
 * @property-read IndexVersion|null $activeVersion
 */
class Collection extends Model
{
    public const ACTIVE = 'active';

    public const PAUSED = 'paused';

    public const DELETING = 'deleting';

    /**
     * Text search configurations (PostgreSQL regconfig) by language.
     */
    public const LANGUAGES = ['simple' => 'simple', 'es' => 'spanish', 'en' => 'english', 'pt' => 'portuguese', 'fr' => 'french', 'de' => 'german', 'it' => 'italian'];

    protected $table = 'rag_collections';

    /**
     * @var list<string>
     */
    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'chunking' => 'array',
            'search' => 'array',
            'acl' => 'array',
            'retention_days' => 'integer',
        ];
    }

    /**
     * @return array{target_tokens: int, overlap_tokens: int, max_tokens: int}
     */
    public static function defaultChunking(): array
    {
        return ['target_tokens' => 350, 'overlap_tokens' => 50, 'max_tokens' => 800];
    }

    /**
     * @return array{method: string, top_k: int, min_score: float|null, min_text_rank: float|null, max_context_tokens: int}
     */
    public static function defaultSearch(): array
    {
        return [
            'method' => (string) config('starter.rag.retrieval.strategy', 'hybrid'),
            'top_k' => (int) config('starter.rag.retrieval.top_k', 6),
            'min_score' => null,
            'min_text_rank' => null,
            'max_context_tokens' => 2000,
        ];
    }

    /**
     * @return BelongsTo<RagConnection, $this>
     */
    public function connection(): BelongsTo
    {
        return $this->belongsTo(RagConnection::class, 'connection_id');
    }

    /**
     * Profile used for the next index version.
     *
     * @return BelongsTo<EmbeddingProfile, $this>
     */
    public function profile(): BelongsTo
    {
        return $this->belongsTo(EmbeddingProfile::class, 'embedding_profile_id');
    }

    /**
     * @return BelongsTo<IndexVersion, $this>
     */
    public function activeVersion(): BelongsTo
    {
        return $this->belongsTo(IndexVersion::class, 'active_index_version_id');
    }

    /**
     * @return HasMany<Source, $this>
     */
    public function sources(): HasMany
    {
        return $this->hasMany(Source::class);
    }

    /**
     * @return HasMany<Document, $this>
     */
    public function documents(): HasMany
    {
        return $this->hasMany(Document::class);
    }

    /**
     * @return HasMany<IndexVersion, $this>
     */
    public function indexVersions(): HasMany
    {
        return $this->hasMany(IndexVersion::class);
    }

    /**
     * @return HasMany<RagJob, $this>
     */
    public function jobs(): HasMany
    {
        return $this->hasMany(RagJob::class);
    }

    /**
     * Index version being built by a reindex, if any.
     */
    public function buildingVersion(): ?IndexVersion
    {
        return $this->indexVersions()->where('status', 'building')->first();
    }

    /**
     * Searched through a retrieval API (no local chunks or index).
     */
    public function isRemote(): bool
    {
        return $this->ragConnection()->kind === ConnectionKind::Api;
    }

    /**
     * Ready to be searched: active, and indexed here or served remotely.
     */
    public function isSearchable(): bool
    {
        return $this->status === self::ACTIVE
            && $this->ragConnection()->isReady()
            && ($this->isRemote() ? $this->external_id !== null : $this->active_index_version_id !== null);
    }

    /**
     * The knowledge connection (inside the model `$this->connection` is
     * Eloquent's database connection name, not the relation).
     */
    protected function ragConnection(): RagConnection
    {
        $connection = $this->getRelationValue('connection');

        if (! $connection instanceof RagConnection) {
            throw new \LogicException('The collection has no knowledge connection.');
        }

        return $connection;
    }

    /**
     * PostgreSQL text search configuration of the collection.
     */
    public function textSearchConfig(): string
    {
        return self::LANGUAGES[$this->language] ?? 'simple';
    }

    /**
     * Collections whose access list matches the principals.
     *
     * @param  Builder<self>  $query
     * @param  list<string>  $principals
     */
    public function scopeVisibleTo(Builder $query, array $principals): void
    {
        $query->where(function (Builder $query) use ($principals) {
            foreach ($principals as $principal) {
                $query->orWhereJsonContains('acl', $principal);
            }
        });
    }

    public function isVisibleTo(mixed $user): bool
    {
        return array_intersect($this->acl, Principals::for($user)) !== [];
    }
}
