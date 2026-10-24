<?php

namespace Starter\Ai\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $price_source_id
 * @property string $status
 * @property array<string, int>|null $stats
 * @property string|null $error
 * @property int|null $triggered_by
 * @property CarbonInterface $started_at
 * @property CarbonInterface|null $finished_at
 * @property-read PriceSource $source
 */
class PriceSyncRun extends Model
{
    protected $table = 'ai_price_sync_runs';

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
            'stats' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<PriceSource, $this>
     */
    public function source(): BelongsTo
    {
        return $this->belongsTo(PriceSource::class, 'price_source_id');
    }
}
