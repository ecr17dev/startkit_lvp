<?php

namespace App\PostgRest;

use RuntimeException;

/**
 * HS256 tokens for PostgREST (the secret is shared with PGRST_JWT_SECRET).
 * Short lived and signed here: a browser cookie never authenticates
 * against PostgREST.
 */
class JwtSigner
{
    public const MIN_SECRET = 32;

    public function __construct(protected ?string $secret) {}

    public static function fromConfig(): self
    {
        $secret = config('starter.postgrest.jwt_secret');

        return new self(is_string($secret) ? $secret : null);
    }

    public function configured(): bool
    {
        return is_string($this->secret) && strlen($this->secret) >= self::MIN_SECRET;
    }

    /**
     * @param  array<string, mixed>  $claims
     */
    public function sign(array $claims): string
    {
        if (! $this->configured()) {
            throw new RuntimeException('POSTGREST_JWT_SECRET must have at least 32 characters.');
        }

        $segments = [
            self::encode(['alg' => 'HS256', 'typ' => 'JWT']),
            self::encode($claims),
        ];

        $segments[] = self::base64(hash_hmac('sha256', implode('.', $segments), (string) $this->secret, true));

        return implode('.', $segments);
    }

    /**
     * Claims of a token signed with this secret and not expired (null
     * otherwise).
     *
     * @return array<string, mixed>|null
     */
    public function verify(string $token): ?array
    {
        $parts = explode('.', $token);

        if (count($parts) !== 3 || ! $this->configured()) {
            return null;
        }

        $expected = self::base64(hash_hmac('sha256', $parts[0].'.'.$parts[1], (string) $this->secret, true));

        if (! hash_equals($expected, $parts[2])) {
            return null;
        }

        $claims = json_decode((string) base64_decode(strtr($parts[1], '-_', '+/')), true);

        if (! is_array($claims) || ! is_int($claims['exp'] ?? null) || $claims['exp'] < time()) {
            return null;
        }

        return $claims;
    }

    /**
     * @param  array<string, mixed>  $value
     */
    protected static function encode(array $value): string
    {
        return self::base64(json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    protected static function base64(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
