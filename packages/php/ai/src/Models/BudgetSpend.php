<?php

namespace Starter\Ai\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Spent and reserved amounts of a budget in one period.
 *
 * @property int $id
 * @property int $budget_id
 * @property string $period_key
 * @property string $spent
 * @property string $reserved
 * @property int $unknown_cost_requests
 * @property-read Budget $budget
 */
class BudgetSpend extends Model
{
    protected $table = 'ai_budget_periods';

    /**
     * @var list<string>
     */
    protected $guarded = ['id'];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'spent' => 0,
        'reserved' => 0,
        'unknown_cost_requests' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'spent' => 'decimal:10',
            'reserved' => 'decimal:10',
        ];
    }

    /**
     * @return BelongsTo<Budget, $this>
     */
    public function budget(): BelongsTo
    {
        return $this->belongsTo(Budget::class, 'budget_id');
    }

    /**
     * @return HasMany<BudgetAlert, $this>
     */
    public function alerts(): HasMany
    {
        return $this->hasMany(BudgetAlert::class, 'budget_period_id');
    }
}
