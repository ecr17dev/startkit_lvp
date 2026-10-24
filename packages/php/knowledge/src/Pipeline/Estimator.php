<?php

namespace Starter\Knowledge\Pipeline;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Starter\Ai\Pricing\CostCalculator;
use Starter\Ai\Pricing\CostEstimator;
use Starter\Knowledge\Ingestion\Chunker;
use Starter\Knowledge\Models\IndexVersion;

/**
 * Volume and cost of embedding content before doing it. Tokens use the
 * identified chars/4 estimate; an unknown price yields an unknown cost.
 */
class Estimator
{
    /**
     * Title and section prefixes added to each chunk, plus the overlap.
     */
    public const OVERHEAD = 1.15;

    public function __construct(protected CostEstimator $costs) {}

    /**
     * @param  array<int, int>  $characterCounts  characters per document
     * @param  list<IndexVersion>  $targets
     * @return array{documents: int, tokens: int, cost: string|null, needs_confirmation: bool}
     */
    public function estimate(array $characterCounts, array $targets): array
    {
        $characters = (int) ceil(array_sum($characterCounts) * self::OVERHEAD);
        $tokens = 0;
        $cost = BigDecimal::zero();
        $known = true;

        foreach ($targets as $target) {
            $tokens += (int) ceil($characters / 4);
            $model = $target->profile->aiModel;
            $result = $model !== null ? $this->costs->estimate($model, $characters, 0) : null;

            if ($result === null || $result->amount === null) {
                $known = false;
            } else {
                $cost = $cost->plus($result->amount);
            }
        }

        $amount = $known ? (string) $cost->toScale(CostCalculator::SCALE, RoundingMode::HalfUp) : null;
        $needs = $tokens > (int) config('starter.rag.indexing.confirm_above_tokens', 250000)
            || IngestionBudget::exceeds($amount, (string) config('starter.rag.indexing.confirm_above_cost', '0.25'));

        return ['documents' => count($characterCounts), 'tokens' => $tokens, 'cost' => $amount, 'needs_confirmation' => $needs];
    }

    public static function tokens(string $text): int
    {
        return Chunker::tokens($text);
    }
}
