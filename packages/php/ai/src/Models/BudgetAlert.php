<?php

namespace Starter\Ai\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $budget_period_id
 * @property int $threshold
 * @property string $spent
 * @property CarbonInterface $triggered_at
 * @property-read BudgetSpend $spend
 */
class BudgetAlert extends Model
{
    protected $table = 'ai_budget_alerts';

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
            'spent' => 'decimal:10',
            'triggered_at' => 'datetime',
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
