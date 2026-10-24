<?php

namespace Starter\Ai\Pricing;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Starter\Ai\Enums\CostStatus;
use Starter\Ai\Enums\PriceComponent;
use Starter\Ai\Enums\PriceUnit;
use Starter\Ai\Models\AiModel;
use Starter\Ai\Models\ModelPrice;
use Starter\Ai\Support\Usage;

/**
 * Multiplies reported units by the prices in force. Exact decimals
 * (brick/math), never floats. Missing prices make the cost unknown.
 */
class CostCalculator
{
    public const SCALE = 10;

    public function calculate(?AiModel $model, Usage $usage, ?CarbonInterface $at = null, string $tier = 'standard', CostStatus $status = CostStatus::Calculated): CostResult
    {
        $units = $this->units($model, $usage);

        if ($units === []) {
            return new CostResult('0', $status, [], [], []);
        }

        if ($model === null) {
            return new CostResult(null, CostStatus::Unknown, [], $units, array_keys($units));
        }

        $prices = $this->pricesFor($model, $at, $tier);
        $total = BigDecimal::zero();
        $applied = [];
        $missing = [];

        foreach ($units as $component => $quantity) {
            $price = $prices->get($component);

            if ($price === null) {
                $missing[] = $component;

                continue;
            }

            $total = $total->plus($this->amountFor($price, $quantity));
            $applied[] = $price->snapshot();
        }

        if ($missing !== []) {
            return new CostResult(null, CostStatus::Unknown, $applied, $units, $missing);
        }

        return new CostResult((string) $total->toScale(self::SCALE, RoundingMode::HalfUp), $status, $applied, $units, []);
    }

    /**
     * Units per billable component. Reasoning tokens are part of the output
     * unless the model has a dedicated reasoning price.
     *
     * @return array<string, int|float>
     */
    public function units(?AiModel $model, Usage $usage): array
    {
        $prices = $model !== null ? $this->pricesFor($model) : collect();
        $separateReasoning = $prices->has(PriceComponent::Reasoning->value) && $usage->reasoningTokens > 0;

        $units = [
            PriceComponent::Input->value => $usage->uncachedInputTokens(),
            PriceComponent::CacheRead->value => $usage->cacheReadTokens,
            PriceComponent::CacheWrite->value => $usage->cacheWriteTokens,
            PriceComponent::Output->value => $separateReasoning ? max(0, $usage->outputTokens - $usage->reasoningTokens) : $usage->outputTokens,
            PriceComponent::Reasoning->value => $separateReasoning ? $usage->reasoningTokens : 0,
            PriceComponent::AudioSeconds->value => $usage->audioSeconds ?? 0,
            PriceComponent::Image->value => $usage->images,
        ];

        // A per request fee applies to every call that consumed something...
        if ($prices->has(PriceComponent::Request->value) && ! $usage->isEmpty()) {
            $units[PriceComponent::Request->value] = 1;
        }

        return array_filter($units, fn (int|float $quantity) => $quantity > 0);
    }

    /**
     * Current prices of the model keyed by component.
     *
     * @return Collection<string, ModelPrice>
     */
    public function pricesFor(AiModel $model, ?CarbonInterface $at = null, string $tier = 'standard'): Collection
    {
        return ModelPrice::query()
            ->with('source')
            ->where('model_id', $model->id)
            ->where('tier', $tier)
            ->effectiveAt($at)
            ->orderByDesc('effective_from')
            ->get()
            ->unique(fn (ModelPrice $price) => $price->component->value)
            ->keyBy(fn (ModelPrice $price) => $price->component->value);
    }

    protected function amountFor(ModelPrice $price, int|float $quantity): BigDecimal
    {
        $amount = BigDecimal::of((string) $price->amount);
        $quantity = BigDecimal::of((string) $quantity);

        // Audio priced per minute while usage is reported in seconds...
        if ($price->component === PriceComponent::AudioSeconds) {
            $quantity = match ($price->unit) {
                PriceUnit::PerMinute => $quantity->dividedBy(60, self::SCALE, RoundingMode::HalfUp),
                default => $quantity,
            };

            return $amount->multipliedBy($quantity);
        }

        return $amount->multipliedBy($quantity)->dividedBy($price->unit->divisor(), self::SCALE + 4, RoundingMode::HalfUp);
    }
}
