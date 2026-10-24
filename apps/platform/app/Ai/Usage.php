<?php

namespace App\Ai;

use Laravel\Ai\Responses\Data\TextUsage;
use Laravel\Ai\Responses\Data\Usage as SdkUsage;

/**
 * Normalized token usage of a single AI call.
 */
final readonly class Usage
{
    public function __construct(
        public int $inputTokens = 0,
        public int $outputTokens = 0,
        public int $cacheReadTokens = 0,
        public int $cacheWriteTokens = 0,
        public int $reasoningTokens = 0,
    ) {}

    /**
     * Create an instance from the usage reported by the Laravel AI SDK.
     */
    public static function fromSdk(SdkUsage $usage): self
    {
        if ($usage instanceof TextUsage) {
            return new self(
                $usage->inputTokens,
                $usage->outputTokens,
                $usage->cacheReadInputTokens ?? 0,
                $usage->cacheWriteInputTokens ?? 0,
                $usage->reasoningTokens ?? 0,
            );
        }

        return new self($usage->inputTokens, $usage->outputTokens);
    }

    /**
     * Get the input tokens that were neither read from nor written to a cache.
     */
    public function uncachedInputTokens(): int
    {
        return max(0, $this->inputTokens - $this->cacheReadTokens - $this->cacheWriteTokens);
    }
}
