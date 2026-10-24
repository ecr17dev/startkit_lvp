<?php

namespace Starter\Ai\Pricing;

use Starter\Ai\Enums\CostStatus;
use Starter\Ai\Models\AiModel;
use Starter\Ai\Support\Usage;

/**
 * Provisional cost of a call before it runs, used to reserve budget.
 *
 * Input tokens are approximated from the characters (≈ 4 per token) and
 * output tokens from the requested maximum. The final cost can differ:
 * the reservation is replaced by the calculated cost when the call ends.
 */
class CostEstimator
{
    public const CHARS_PER_TOKEN = 4;

    public const DEFAULT_OUTPUT_TOKENS = 1024;

    public function __construct(protected CostCalculator $calculator) {}

    public function estimate(?AiModel $model, int $inputCharacters, ?int $maxOutputTokens = null): CostResult
    {
        $outputTokens = $maxOutputTokens ?? $model->max_output_tokens ?? self::DEFAULT_OUTPUT_TOKENS;
        $outputTokens = min($outputTokens, $model->max_output_tokens ?? $outputTokens);

        $usage = new Usage(
            inputTokens: (int) ceil(max(0, $inputCharacters) / self::CHARS_PER_TOKEN),
            outputTokens: max(0, $outputTokens),
        );

        return $this->calculator->calculate($model, $usage, status: CostStatus::Estimated);
    }
}
