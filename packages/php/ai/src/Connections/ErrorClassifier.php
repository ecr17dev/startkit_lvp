<?php

namespace Starter\Ai\Connections;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Laravel\Ai\Exceptions\InsufficientCreditsException;
use Laravel\Ai\Exceptions\ProviderConnectionException;
use Laravel\Ai\Exceptions\ProviderOverloadedException;
use Laravel\Ai\Exceptions\RateLimitedException;
use Starter\Ai\Enums\ErrorType;
use Starter\Ai\Exceptions\BudgetExceededException;
use Throwable;

/**
 * Distinguishes authentication, quota, network and missing model errors.
 */
class ErrorClassifier
{
    public static function classify(Throwable $exception): ErrorType
    {
        return match (true) {
            $exception instanceof BudgetExceededException => ErrorType::Budget,
            $exception instanceof RateLimitedException => ErrorType::RateLimit,
            $exception instanceof InsufficientCreditsException => ErrorType::Quota,
            $exception instanceof ProviderOverloadedException => ErrorType::Overloaded,
            $exception instanceof ProviderConnectionException, $exception instanceof ConnectionException => ErrorType::Network,
            $exception instanceof RequestException && $exception->response !== null => self::fromStatus($exception->response->status(), (string) $exception->response->body()),
            $exception instanceof InvalidArgumentException => ErrorType::Configuration,
            default => self::fromMessage($exception->getMessage(), $exception->getPrevious()),
        };
    }

    public static function fromStatus(int $status, string $body = ''): ErrorType
    {
        return match (true) {
            in_array($status, [401, 403], true) => ErrorType::Authentication,
            $status === 402 => ErrorType::Quota,
            $status === 404 => ErrorType::ModelNotFound,
            $status === 429 => Str::contains(Str::lower($body), ['quota', 'insufficient', 'credit']) ? ErrorType::Quota : ErrorType::RateLimit,
            in_array($status, [400, 409, 413, 422], true) => Str::contains(Str::lower($body), ['model']) && Str::contains(Str::lower($body), ['not found', 'does not exist', 'not exist', 'invalid model', 'unknown model'])
                ? ErrorType::ModelNotFound
                : ErrorType::InvalidRequest,
            in_array($status, [500, 502, 503, 504, 529], true) => ErrorType::Overloaded,
            default => ErrorType::Unknown,
        };
    }

    public static function fromMessage(string $message, ?Throwable $previous = null): ErrorType
    {
        if ($previous !== null && ($type = self::classify($previous)) !== ErrorType::Unknown) {
            return $type;
        }

        $message = Str::lower($message);

        return match (true) {
            Str::contains($message, ['api key', 'apikey', 'unauthorized', 'authentication', 'permission denied', 'invalid x-api-key', 'forbidden']) => ErrorType::Authentication,
            Str::contains($message, ['insufficient_quota', 'quota', 'credit balance', 'billing']) => ErrorType::Quota,
            Str::contains($message, ['rate limit', 'too many requests']) => ErrorType::RateLimit,
            Str::contains($message, ['could not resolve host', 'curl error', 'connection refused', 'timed out', 'network']) => ErrorType::Network,
            Str::contains($message, ['model']) && Str::contains($message, ['not found', 'does not exist', 'not available', 'unknown model']) => ErrorType::ModelNotFound,
            default => ErrorType::Unknown,
        };
    }

    /**
     * Message safe to store: no secrets, bounded length.
     */
    public static function safeMessage(Throwable $exception): string
    {
        $message = preg_replace('/(sk|pk|key|token)[-_][A-Za-z0-9_\-]{8,}/i', '[redacted]', $exception->getMessage()) ?? '';

        return Str::limit(trim($message) ?: class_basename($exception), 500);
    }
}
