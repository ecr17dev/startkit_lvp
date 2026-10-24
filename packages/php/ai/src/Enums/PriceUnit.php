<?php

namespace Starter\Ai\Enums;

enum PriceUnit: string
{
    case PerMillionTokens = 'per_million_tokens';
    case PerThousandTokens = 'per_thousand_tokens';
    case PerToken = 'per_token';
    case PerMinute = 'per_minute';
    case PerSecond = 'per_second';
    case PerRequest = 'per_request';
    case PerImage = 'per_image';
    case PerUnit = 'per_unit';

    /**
     * Number of usage units covered by the price (e.g. 1,000,000 tokens).
     */
    public function divisor(): int
    {
        return match ($this) {
            self::PerMillionTokens => 1_000_000,
            self::PerThousandTokens => 1_000,
            default => 1,
        };
    }
}
