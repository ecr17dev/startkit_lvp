<?php

namespace App\PostgRest;

use App\Models\User;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Starter\Administration\Health\HealthCheck;
use Starter\Knowledge\Support\Principals;
use Throwable;

/**
 * Server to server access to PostgREST. Every request carries a short JWT
 * signed here with the identity and the principals of the acting user:
 * the views of the api schema filter with those claims, so PostgREST
 * never sees more than the user may read.
 */
class PostgRest
{
    public function enabled(): bool
    {
        return (bool) config('starter.features.postgrest');
    }

    public function url(): string
    {
        return rtrim((string) config('starter.postgrest.url'), '/');
    }

    public function signer(): JwtSigner
    {
        return JwtSigner::fromConfig();
    }

    /**
     * Claims of a user (or of the system, without principals).
     *
     * @return array<string, mixed>
     */
    public function claims(?User $user, ?int $ttl = null): array
    {
        $now = time();

        return [
            'role' => (string) config('starter.postgrest.roles.user'),
            'sub' => $user !== null ? (string) $user->getKey() : null,
            'principals' => $user !== null ? Principals::for($user) : [],
            'aud' => 'postgrest',
            'iat' => $now,
            'exp' => $now + max(30, min(3600, $ttl ?? (int) config('starter.postgrest.ttl', 300))),
        ];
    }

    public function token(?User $user, ?int $ttl = null): string
    {
        return $this->signer()->sign($this->claims($user, $ttl));
    }

    /**
     * Readiness: required only while the feature is enabled.
     */
    public function health(): HealthCheck
    {
        if (! $this->enabled()) {
            return new HealthCheck('postgrest', HealthCheck::SKIPPED, false, 'disabled');
        }

        if (! $this->signer()->configured()) {
            return new HealthCheck('postgrest', HealthCheck::FAILED, true, 'POSTGREST_JWT_SECRET must have at least 32 characters.');
        }

        $started = microtime(true);

        try {
            $response = $this->as(null)->post('rpc/whoami');
            $status = $response->successful() ? HealthCheck::OK : HealthCheck::FAILED;
            $message = $response->successful() ? 'role='.($response->json('role') ?? '?') : 'HTTP '.$response->status();
        } catch (Throwable $exception) {
            [$status, $message] = [HealthCheck::FAILED, mb_substr($exception->getMessage(), 0, 200)];
        }

        return new HealthCheck('postgrest', $status, true, $message, round((microtime(true) - $started) * 1000, 2));
    }

    /**
     * HTTP client acting as the user (short token, JSON, no redirects).
     */
    public function as(?User $user): PendingRequest
    {
        return Http::baseUrl($this->url().'/')
            ->acceptJson()
            ->withToken($this->token($user, 60))
            ->timeout(max(1, (int) config('starter.postgrest.timeout', 10)))
            ->withOptions(['allow_redirects' => false]);
    }
}
