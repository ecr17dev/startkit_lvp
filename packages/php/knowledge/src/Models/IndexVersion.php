<?php

namespace Starter\Knowledge\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Starter\Knowledge\Enums\IndexVersionStatus;

/**
 * A complete indexed set of a collection (profile + chunking). Queries
 * only read the active version; a reindex builds a new one and publishes
 * it when it is complete, keeping the previous one available meanwhile.
 *
 * @property int $id
 * @property int $collection_id
 * @property int $embedding_profile_id
 * @property int $number
 * @property IndexVersionStatus $status
 * @property string $reason
 * @property array{target_tokens: int, overlap_tokens: int, max_tokens: int} $chunking
 * @property int $documents
 * @property int $chunks
 * @property int $tokens
 * @property string $cost
 * @property bool $cost_known
 * @property int|null $created_by
 * @property CarbonInterface|null $published_at
 * @property CarbonInterface|null $purged_at
 * @property CarbonInterface $created_at
 * @property CarbonInterface $updated_at
 * @property-read Collection $collection
 * @property-read EmbeddingProfile $profile
 */
class IndexVersion extends Model
{
    protected $table = 'rag_index_versions';

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
            'status' => IndexVersionStatus::class,
            'chunking' => 'array',
            'documents' => 'integer',
            'chunks' => 'integer',
            'tokens' => 'integer',
            'cost' => 'decimal:10',
            'cost_known' => 'boolean',
            'published_at' => 'datetime',
            'purged_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Collection, $this>
     */
    public function collection(): BelongsTo
    {
        return $this->belongsTo(Collection::class);
    }

    /**
     * @return BelongsTo<EmbeddingProfile, $this>
     */
    public function profile(): BelongsTo
    {
        return $this->belongsTo(EmbeddingProfile::class, 'embedding_profile_id');
    }

    /**
     * Whether new document versions must also be written here.
     */
    public function receivesWrites(): bool
    {
        return in_array($this->status, [IndexVersionStatus::Active, IndexVersionStatus::Building], true);
    }
}
