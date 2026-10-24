<?php

namespace Starter\Ai\Pricing;

use Brick\Math\BigDecimal;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Starter\Ai\Enums\PriceComponent;
use Starter\Ai\Enums\PriceUnit;
use Starter\Ai\Models\AiModel;
use Starter\Ai\Models\ModelPrice;
use Starter\Ai\Models\PriceSource;

/**
 * Writes prices without altering the history: a change closes the current
 * price and opens a new version.
 */
class PriceBook
{
    public const CREATED = 'created';

    public const UPDATED = 'updated';

    public const UNCHANGED = 'unchanged';

    /**
     * @return self::CREATED|self::UPDATED|self::UNCHANGED
     */
    public function set(
        AiModel $model,
        PriceComponent $component,
        string $amount,
        PriceSource $source,
        ?PriceUnit $unit = null,
        string $tier = 'standard',
        string $currency = 'USD',
        ?string $notes = null,
        ?int $createdBy = null,
        ?CarbonInterface $retrievedAt = null,
    ): string {
        $unit ??= $component->defaultUnit();
        $amount = (string) BigDecimal::of($amount)->strippedOfTrailingZeros();

        return DB::transaction(function () use ($model, $component, $amount, $source, $unit, $tier, $currency, $notes, $createdBy, $retrievedAt) {
            $current = ModelPrice::query()
                ->where('model_id', $model->id)
                ->where('component', $component->value)
                ->where('tier', $tier)
                ->whereNull('effective_to')
                ->lockForUpdate()
                ->first();

            if ($current !== null
                && $current->unit === $unit
                && $current->currency === $currency
                && $current->price_source_id === $source->id
                && BigDecimal::of((string) $current->amount)->isEqualTo($amount)) {
                if ($retrievedAt !== null) {
                    $current->forceFill(['retrieved_at' => $retrievedAt])->save();
                }

                return self::UNCHANGED;
            }

            $now = now();
            $current?->forceFill(['effective_to' => $now])->save();

            ModelPrice::query()->create([
                'model_id' => $model->id,
                'price_source_id' => $source->id,
                'component' => $component,
                'unit' => $unit,
                'amount' => $amount,
                'currency' => $currency,
                'tier' => $tier,
                'effective_from' => $now,
                'retrieved_at' => $retrievedAt ?? $now,
                'notes' => $notes,
                'created_by' => $createdBy,
            ]);

            return $current === null ? self::CREATED : self::UPDATED;
        });
    }

    /**
     * Stop applying a price from now on (the history is kept).
     */
    public function close(AiModel $model, PriceComponent $component, string $tier = 'standard'): bool
    {
        return ModelPrice::query()
            ->where('model_id', $model->id)
            ->where('component', $component->value)
            ->where('tier', $tier)
            ->whereNull('effective_to')
            ->update(['effective_to' => now(), 'updated_at' => now()]) > 0;
    }

    /**
     * Close the current price of a component when it came from the source
     * (e.g. a gateway stopped listing it).
     */
    public function closeFromSource(AiModel $model, PriceComponent $component, PriceSource $source, string $tier = 'standard'): bool
    {
        return ModelPrice::query()
            ->where('model_id', $model->id)
            ->where('component', $component->value)
            ->where('tier', $tier)
            ->where('price_source_id', $source->id)
            ->whereNull('effective_to')
            ->update(['effective_to' => now(), 'updated_at' => now()]) > 0;
    }
}
