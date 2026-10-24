<?php

namespace Starter\Ai\Enums;

/**
 * Normalized failure categories (connection tests and the ledger).
 */
enum ErrorType: string
{
    case Authentication = 'authentication';
    case Quota = 'quota';
    case RateLimit = 'rate_limit';
    case Network = 'network';
    case ModelNotFound = 'model_not_found';
    case InvalidRequest = 'invalid_request';
    case Overloaded = 'overloaded';
    case Budget = 'budget';
    case Configuration = 'configuration';
    case Unknown = 'unknown';

    public function message(): string
    {
        return match ($this) {
            self::Authentication => 'The credential was rejected by the provider.',
            self::Quota => 'The provider account has no credit or quota left.',
            self::RateLimit => 'The provider rate limit was reached.',
            self::Network => 'The provider could not be reached.',
            self::ModelNotFound => 'The model does not exist or is not available for this credential.',
            self::InvalidRequest => 'The provider rejected the request.',
            self::Overloaded => 'The provider is overloaded. Try again later.',
            self::Budget => 'An AI budget limit was reached.',
            self::Configuration => 'The connection is not configured correctly.',
            self::Unknown => 'The provider returned an unexpected error.',
        };
    }
}
