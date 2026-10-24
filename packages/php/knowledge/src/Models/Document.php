<?php

namespace Starter\Knowledge\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Starter\Knowledge\Enums\DocumentStatus;

/**
 * A logical document of a source (a file, a page, an adapter record),
 * identified by its external id. Its content lives in versions.
 *
 * @property int $id
 * @property int $collection_id
 * @property int $source_id
 * @property string $external_id
 * @property string $external_hash
 * @property string|null $title
 * @property string|null $uri
 * @property string|null $mime_type
 * @property DocumentStatus $status
 * @property int|null $current_version_id
 * @property int|null $latest_version_id
 * @property string|null $content_hash
 * @property string|null $etag
 * @property string|null $last_modified
 * @property int $size
 * @property int $chunk_count
 * @property int $token_count
 * @property list<string>|null $acl
 * @property array<string, mixed>|null $metadata
 * @property string|null $error
 * @property CarbonInterface|null $indexed_at
 * @property CarbonInterface|null $seen_at
 * @property CarbonInterface|null $deleted_at
 * @property CarbonInterface $created_at
 * @property CarbonInterface $updated_at
 * @property-read Collection $collection
 * @property-read Source $source
 * @property-read DocumentVersion|null $currentVersion
 * @property-read DocumentVersion|null $latestVersion
 */
class Document extends Model
{
    protected $table = 'rag_documents';

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
            'status' => DocumentStatus::class,
            'size' => 'integer',
            'chunk_count' => 'integer',
            'token_count' => 'integer',
            'acl' => 'array',
            'metadata' => 'array',
            'indexed_at' => 'datetime',
            'seen_at' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }

    public static function hashExternalId(string $externalId): string
    {
        return hash('sha256', $externalId);
    }

    /**
     * @return BelongsTo<Collection, $this>
     */
    public function collection(): BelongsTo
    {
        return $this->belongsTo(Collection::class);
    }

    /**
     * @return BelongsTo<Source, $this>
     */
    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class);
    }

    /**
     * @return HasMany<DocumentVersion, $this>
     */
    public function versions(): HasMany
    {
        return $this->hasMany(DocumentVersion::class);
    }

    /**
     * Version currently searchable.
     *
     * @return BelongsTo<DocumentVersion, $this>
     */
    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(DocumentVersion::class, 'current_version_id');
    }

    /**
     * Last extracted version (may still be indexing).
     *
     * @return BelongsTo<DocumentVersion, $this>
     */
    public function latestVersion(): BelongsTo
    {
        return $this->belongsTo(DocumentVersion::class, 'latest_version_id');
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeAlive(Builder $query): void
    {
        $query->whereNull('deleted_at');
    }

    /**
     * Access list of the document (its own, else the source's).
     *
     * @return list<string>
     */
    public function effectiveAcl(): array
    {
        return $this->acl ?: $this->source->effectiveAcl();
    }

    public function isDeleted(): bool
    {
        return $this->deleted_at !== null;
    }
}
