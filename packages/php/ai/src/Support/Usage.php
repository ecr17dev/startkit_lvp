<?php

namespace Starter\Ai\Support;

use Laravel\Ai\Responses\Data\TextUsage;
use Laravel\Ai\Responses\Data\TranscriptionUsage;
use Laravel\Ai\Responses\Data\Usage as SdkUsage;

/**
 * Normalized units reported for one call.
 */
final readonly class Usage
{
    public function __construct(
        public int $inputTokens = 0,
        public int $outputTokens = 0,
        public int $cacheReadTokens = 0,
        public int $cacheWriteTokens = 0,
        public int $reasoningTokens = 0,
        public ?float $audioSeconds = null,
        public int $images = 0,
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
                $usage instanceof TranscriptionUsage ? $usage->audioSeconds : null,
            );
        }

        return new self($usage->inputTokens, $usage->outputTokens);
    }

    /**
     * Input tokens neither read from nor written to a prompt cache.
     */
    public function uncachedInputTokens(): int
    {
        return max(0, $this->inputTokens - $this->cacheReadTokens - $this->cacheWriteTokens);
    }

    public function totalTokens(): int
    {
        return $this->inputTokens + $this->outputTokens;
    }

    public function isEmpty(): bool
    {
        return $this->totalTokens() === 0 && ($this->audioSeconds ?? 0) <= 0 && $this->images === 0;
    }

    public function add(self $other): self
    {
        return new self(
            $this->inputTokens + $other->inputTokens,
            $this->outputTokens + $other->outputTokens,
            $this->cacheReadTokens + $other->cacheReadTokens,
            $this->cacheWriteTokens + $other->cacheWriteTokens,
            $this->reasoningTokens + $other->reasoningTokens,
            $this->audioSeconds === null && $other->audioSeconds === null ? null : ($this->audioSeconds ?? 0) + ($other->audioSeconds ?? 0),
            $this->images + $other->images,
        );
    }

    /**
     * @return array<string, int|float>
     */
    public function toArray(): array
    {
        return array_filter([
            'input_tokens' => $this->inputTokens,
            'output_tokens' => $this->outputTokens,
            'cache_read_tokens' => $this->cacheReadTokens,
            'cache_write_tokens' => $this->cacheWriteTokens,
            'reasoning_tokens' => $this->reasoningTokens,
            'audio_seconds' => $this->audioSeconds,
            'images' => $this->images,
        ], fn ($value) => $value !== null && $value !== 0 && $value !== 0.0);
    }

    /**
     * Columns of the ledger tables.
     *
     * @return array{input_tokens: int, output_tokens: int, cache_read_tokens: int, cache_write_tokens: int, reasoning_tokens: int}
     */
    public function columns(): array
    {
        return [
            'input_tokens' => $this->inputTokens,
            'output_tokens' => $this->outputTokens,
            'cache_read_tokens' => $this->cacheReadTokens,
            'cache_write_tokens' => $this->cacheWriteTokens,
            'reasoning_tokens' => $this->reasoningTokens,
        ];
    }
}
