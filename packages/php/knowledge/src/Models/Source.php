<?php

namespace Starter\Knowledge\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Starter\Knowledge\Enums\SourceStatus;
use Starter\Knowledge\Enums\SourceType;

/**
 * @property int $id
 * @property int $collection_id
 * @property SourceType $type
 * @property string $name
 * @property array<string, mixed> $config
 * @property list<string>|null $acl
 * @property SourceStatus $status
 * @property string|null $schedule
 * @property CarbonInterface|null $next_sync_at
 * @property CarbonInterface|null $last_sync_at
 * @property string|null $last_job_id
 * @property string|null $last_error
 * @property int|null $created_by
 * @property CarbonInterface $created_at
 * @property CarbonInterface $updated_at
 * @property-read Collection $collection
 */
class Source extends Model
{
    public const SCHEDULES = ['hourly', 'daily', 'weekly'];

    protected $table = 'rag_sources';

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
            'type' => SourceType::class,
            'config' => 'array',
            'acl' => 'array',
            'status' => SourceStatus::class,
            'next_sync_at' => 'datetime',
            'last_sync_at' => 'datetime',
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
     * @return HasMany<Document, $this>
     */
    public function documents(): HasMany
    {
        return $this->hasMany(Document::class);
    }

    /**
     * @return HasMany<RagJob, $this>
     */
    public function jobs(): HasMany
    {
        return $this->hasMany(RagJob::class);
    }

    /**
     * Access list applied to the documents of the source.
     *
     * @return list<string>
     */
    public function effectiveAcl(): array
    {
        return $this->acl ?: $this->collection->acl;
    }

    public function isActive(): bool
    {
        return $this->status === SourceStatus::Active;
    }

    /**
     * Next scheduled sync after the given moment.
     */
    public function nextRunAfter(CarbonInterface $moment): ?CarbonInterface
    {
        return match ($this->schedule) {
            'hourly' => $moment->copy()->addHour(),
            'daily' => $moment->copy()->addDay(),
            'weekly' => $moment->copy()->addWeek(),
            default => null,
        };
    }
}
