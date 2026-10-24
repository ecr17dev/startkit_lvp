<?php

namespace Starter\Knowledge\Pipeline;

use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Starter\Knowledge\Models\RagJob;

/**
 * Confirmed maximum cost of an ingestion. Children reserve the estimate of
 * each embedding call on the parent row with a conditional update (atomic
 * across workers) and settle the real cost afterwards.
 */
class IngestionBudget
{
    /**
     * @return bool false when the reservation would exceed the maximum
     */
    public function reserve(RagJob $owner, ?string $amount): bool
    {
        if ($owner->max_cost === null || $amount === null) {
            return true;
        }

        return DB::update(
            'UPDATE rag_jobs SET reserved = reserved + CAST(? AS numeric) WHERE id = ? AND cost + reserved + CAST(? AS numeric) <= max_cost',
            [$amount, $owner->id, $amount],
        ) === 1;
    }

    public function settle(RagJob $owner, ?string $reserved, ?string $actual): void
    {
        DB::update(
            'UPDATE rag_jobs SET reserved = GREATEST(reserved - CAST(? AS numeric), 0), cost = cost + CAST(? AS numeric), cost_known = cost_known AND CAST(? AS boolean), updated_at = now() WHERE id = ?',
            [$owner->max_cost === null ? '0' : ($reserved ?? '0'), $actual ?? '0', $actual !== null ? 'true' : 'false', $owner->id],
        );
    }

    /**
     * Charge a cost to a job without reservation (its own record).
     */
    public function charge(RagJob $job, ?string $actual): void
    {
        DB::update(
            'UPDATE rag_jobs SET cost = cost + CAST(? AS numeric), cost_known = cost_known AND CAST(? AS boolean), updated_at = now() WHERE id = ?',
            [$actual ?? '0', $actual !== null ? 'true' : 'false', $job->id],
        );
    }

    public static function exceeds(?string $amount, string $limit): bool
    {
        return $amount !== null && BigDecimal::of($amount)->isGreaterThan(BigDecimal::of($limit));
    }
}
