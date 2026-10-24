<?php

namespace Starter\Ai\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Immutable copy of the prices and units behind an estimate, a calculated
 * cost or a reconciled cost.
 *
 * @property int $id
 * @property string $ai_request_id
 * @property int|null $ai_attempt_id
 * @property string $kind
 * @property string|null $amount
 * @property string $currency
 * @property list<array<string, mixed>>|null $prices
 * @property array<string, int|float>|null $units
 * @property list<string>|null $missing
 * @property CarbonInterface $created_at
 */
class CostSnapshot extends Model
{
    public const UPDATED_AT = null;

    public const ESTIMATE = 'estimate';

    public const CALCULATED = 'calculated';

    public const RECONCILED = 'reconciled';

    protected $table = 'ai_cost_snapshots';

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
            'amount' => 'decimal:10',
            'prices' => 'array',
            'units' => 'array',
            'missing' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<AiRequest, $this>
     */
    public function request(): BelongsTo
    {
        return $this->belongsTo(AiRequest::class, 'ai_request_id');
    }
}
