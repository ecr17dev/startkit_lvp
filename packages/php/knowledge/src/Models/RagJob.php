<?php

namespace Starter\Knowledge\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Starter\Knowledge\Enums\JobStage;
use Starter\Knowledge\Enums\JobStatus;
use Starter\Knowledge\Enums\JobType;

/**
 * Durable record of a pipeline job: stage, known progress, attempts,
 * sanitized error, timings, units and cost (kept even if cancelled). The
 * Laravel queue only carries the id.
 *
 * @property string $id
 * @property string|null $parent_id
 * @property int|null $collection_id
 * @property int|null $source_id
 * @property int|null $document_id
 * @property int|null $index_version_id
 * @property JobType $type
 * @property JobStatus $status
 * @property JobStage|null $stage
 * @property int|null $progress_total
 * @property int $progress_done
 * @property int $attempts
 * @property array<string, mixed>|null $payload
 * @property array<string, int>|null $units
 * @property array<string, int>|null $timings
 * @property array{tokens?: int, cost?: string|null, documents?: int}|null $estimate
 * @property string $cost
 * @property bool $cost_known
 * @property string|null $max_cost
 * @property string|null $error
 * @property string|null $error_type
 * @property string $trigger
 * @property int|null $created_by
 * @property CarbonInterface|null $cancel_requested_at
 * @property CarbonInterface|null $heartbeat_at
 * @property CarbonInterface|null $started_at
 * @property CarbonInterface|null $finished_at
 * @property CarbonInterface $created_at
 * @property CarbonInterface $updated_at
 * @property-read Collection|null $collection
 * @property-read Source|null $source
 * @property-read Document|null $document
 * @property-read IndexVersion|null $indexVersion
 */
class RagJob extends Model
{
    use HasUuids;

    protected $table = 'rag_jobs';

    /**
     * @var list<string>
     */
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => JobType::class,
            'status' => JobStatus::class,
            'stage' => JobStage::class,
            'progress_total' => 'integer',
            'progress_done' => 'integer',
            'attempts' => 'integer',
            'payload' => 'array',
            'units' => 'array',
            'timings' => 'array',
            'estimate' => 'array',
            'cost' => 'decimal:10',
            'cost_known' => 'boolean',
            'max_cost' => 'decimal:10',
            'cancel_requested_at' => 'datetime',
            'heartbeat_at' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
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
     * @return BelongsTo<Source, $this>
     */
    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class);
    }

    /**
     * @return BelongsTo<Document, $this>
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    /**
     * @return BelongsTo<IndexVersion, $this>
     */
    public function indexVersion(): BelongsTo
    {
        return $this->belongsTo(IndexVersion::class);
    }

    /**
     * @return BelongsTo<self, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * @return HasMany<self, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function isFinished(): bool
    {
        return $this->status->finished();
    }

    public function cancelRequested(): bool
    {
        return $this->cancel_requested_at !== null;
    }
}
