<?php

namespace Starter\Ai\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Starter\Ai\Enums\BudgetPeriod;
use Starter\Ai\Enums\BudgetScope;

/**
 * @property int $id
 * @property string $name
 * @property BudgetScope $scope
 * @property string|null $scope_value
 * @property BudgetPeriod $period
 * @property string $limit_amount
 * @property string $currency
 * @property list<int>|null $alert_thresholds
 * @property bool $hard_limit
 * @property bool $block_unknown_cost
 * @property bool $is_active
 * @property int|null $created_by
 * @property CarbonInterface $created_at
 * @property CarbonInterface $updated_at
 */
class Budget extends Model
{
    protected $table = 'ai_budgets';

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
            'scope' => BudgetScope::class,
            'period' => BudgetPeriod::class,
            'limit_amount' => 'decimal:6',
            'alert_thresholds' => 'array',
            'hard_limit' => 'boolean',
            'block_unknown_cost' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return HasMany<BudgetSpend, $this>
     */
    public function spends(): HasMany
    {
        return $this->hasMany(BudgetSpend::class, 'budget_id');
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * Thresholds (percent of the limit) that trigger alerts.
     *
     * @return list<int>
     */
    public function thresholds(): array
    {
        $values = array_map('intval', $this->alert_thresholds ?? [80, 100]);
        sort($values);

        return array_values(array_unique(array_filter($values, fn (int $value) => $value > 0 && $value <= 1000)));
    }

    /**
     * Spend record of the current period.
     */
    public function currentSpend(?CarbonInterface $at = null): BudgetSpend
    {
        return BudgetSpend::query()->firstOrCreate([
            'budget_id' => $this->id,
            'period_key' => $this->period->key($at ?? now()),
        ]);
    }
}
