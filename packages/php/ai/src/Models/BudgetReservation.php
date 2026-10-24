<?php

namespace Starter\Ai\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $budget_period_id
 * @property string $ai_request_id
 * @property string $amount
 * @property string|null $settled_amount
 * @property string $status
 * @property CarbonInterface $expires_at
 * @property-read BudgetSpend $spend
 */
class BudgetReservation extends Model
{
    public const RESERVED = 'reserved';

    public const SETTLED = 'settled';

    public const RELEASED = 'released';

    protected $table = 'ai_budget_reservations';

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
            'settled_amount' => 'decimal:10',
            'expires_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<BudgetSpend, $this>
     */
    public function spend(): BelongsTo
    {
        return $this->belongsTo(BudgetSpend::class, 'budget_period_id');
    }
}
