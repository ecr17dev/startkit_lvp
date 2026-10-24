<?php

namespace Starter\Ai\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Starter\Ai\Enums\PriceComponent;
use Starter\Ai\Enums\PriceUnit;

/**
 * A versioned price. Rows are never edited: a change closes the current
 * row (effective_to) and opens a new one.
 *
 * @property int $id
 * @property int $model_id
 * @property int $price_source_id
 * @property PriceComponent $component
 * @property PriceUnit $unit
 * @property string $amount
 * @property string $currency
 * @property string $tier
 * @property CarbonInterface $effective_from
 * @property CarbonInterface|null $effective_to
 * @property CarbonInterface|null $retrieved_at
 * @property string|null $notes
 * @property int|null $created_by
 * @property-read AiModel $model
 * @property-read PriceSource $source
 */
class ModelPrice extends Model
{
    protected $table = 'ai_model_prices';

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
            'component' => PriceComponent::class,
            'unit' => PriceUnit::class,
            'amount' => 'decimal:10',
            'effective_from' => 'datetime',
            'effective_to' => 'datetime',
            'retrieved_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<AiModel, $this>
     */
    public function model(): BelongsTo
    {
        return $this->belongsTo(AiModel::class, 'model_id');
    }

    /**
     * @return BelongsTo<PriceSource, $this>
     */
    public function source(): BelongsTo
    {
        return $this->belongsTo(PriceSource::class, 'price_source_id');
    }

    /**
     * Prices in force at the given moment.
     *
     * @param  Builder<self>  $query
     */
    public function scopeEffectiveAt(Builder $query, ?CarbonInterface $at = null): void
    {
        $at ??= now();

        $query->where('effective_from', '<=', $at)
            ->where(fn ($query) => $query->whereNull('effective_to')->orWhere('effective_to', '>', $at));
    }

    /**
     * Snapshot persisted with each cost (the history never changes).
     *
     * @return array<string, mixed>
     */
    public function snapshot(): array
    {
        return [
            'price_id' => $this->id,
            'source' => $this->relationLoaded('source') ? $this->source->key : $this->price_source_id,
            'component' => $this->component->value,
            'unit' => $this->unit->value,
            'amount' => (string) $this->amount,
            'currency' => $this->currency,
            'tier' => $this->tier,
            'effective_from' => $this->effective_from->toIso8601String(),
        ];
    }
}
