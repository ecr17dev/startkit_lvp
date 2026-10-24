<?php

namespace Starter\Ai\Enums;

/**
 * Billable dimension of a model price.
 */
enum PriceComponent: string
{
    case Input = 'input';
    case Output = 'output';
    case CacheRead = 'cache_read';
    case CacheWrite = 'cache_write';
    case Reasoning = 'reasoning';
    case AudioInput = 'audio_input';
    case AudioSeconds = 'audio_seconds';
    case Request = 'request';
    case Image = 'image';
    case WebSearch = 'web_search';

    /**
     * Default unit of the component.
     */
    public function defaultUnit(): PriceUnit
    {
        return match ($this) {
            self::Input, self::Output, self::CacheRead, self::CacheWrite, self::Reasoning, self::AudioInput => PriceUnit::PerMillionTokens,
            self::AudioSeconds => PriceUnit::PerMinute,
            self::Request, self::WebSearch => PriceUnit::PerRequest,
            self::Image => PriceUnit::PerImage,
        };
    }
}
