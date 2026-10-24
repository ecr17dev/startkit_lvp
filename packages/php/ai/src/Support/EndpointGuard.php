<?php

namespace Starter\Ai\Support;

/**
 * Validates the endpoint of a connection before the server calls it.
 *
 * Cloud providers must use public HTTPS endpoints. Local runtimes (Ollama,
 * custom OpenAI compatible servers) may use private networks, but cloud
 * metadata addresses are always refused.
 */
class EndpointGuard
{
    public static function violation(string $url, bool $allowPrivate): ?string
    {
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower(trim((string) ($parts['host'] ?? ''), '[]'));

        if (! in_array($scheme, ['http', 'https'], true) || $host === '') {
            return 'The endpoint must be an http(s) URL.';
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            return 'The endpoint cannot contain credentials.';
        }

        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (gethostbynamel($host) ?: []);

        foreach ($ips as $ip) {
            if (str_starts_with($ip, '169.254.') || $ip === '100.100.100.200' || str_starts_with($ip, 'fd00:ec2')) {
                return 'Cloud metadata addresses are not allowed.';
            }

            if (! $allowPrivate && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                return 'The endpoint must be a public address.';
            }
        }

        if (! $allowPrivate && $scheme !== 'https') {
            return 'The endpoint must use HTTPS.';
        }

        return null;
    }
}
