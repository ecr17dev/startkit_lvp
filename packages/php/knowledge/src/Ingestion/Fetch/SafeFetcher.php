<?php

namespace Starter\Knowledge\Ingestion\Fetch;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Reads public URLs while preventing SSRF: only http(s) on allowed ports,
 * every address the host resolves to must be public (no loopback, private,
 * link-local, CGNAT, metadata or reserved ranges), the request is pinned
 * to the validated address (no DNS rebinding), redirects are followed
 * manually and validated hop by hop, and time, size and content types are
 * limited. Private sources use dedicated connectors, never this reader.
 */
class SafeFetcher
{
    /**
     * CIDR ranges that are never contacted.
     */
    public const BLOCKED_RANGES = [
        '0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '169.254.0.0/16', '172.16.0.0/12',
        '192.0.0.0/24', '192.0.2.0/24', '192.88.99.0/24', '192.168.0.0/16', '198.18.0.0/15', '198.51.100.0/24',
        '203.0.113.0/24', '224.0.0.0/4', '240.0.0.0/4', '255.255.255.255/32',
        '::/128', '::1/128', '::ffff:0:0/96', '64:ff9b::/96', '100::/64', '2001:db8::/32', 'fc00::/7', 'fe80::/10', 'ff00::/8',
    ];

    public const HTML_TYPES = ['text/html', 'application/xhtml+xml'];

    public const DOCUMENT_TYPES = ['text/html', 'application/xhtml+xml', 'text/plain', 'text/markdown', 'text/x-markdown', 'application/pdf'];

    public const SITEMAP_TYPES = ['application/xml', 'text/xml', 'application/x-xml', 'text/plain'];

    public function __construct(protected HostResolver $resolver) {}

    /**
     * @param  list<string>  $accept  allowed MIME types
     * @param  array{etag?: string|null, last_modified?: string|null}  $conditional
     *
     * @throws BlockedUrlException|FetchException
     */
    public function fetch(string $url, array $accept = self::DOCUMENT_TYPES, array $conditional = []): FetchedResource
    {
        $config = (array) config('starter.rag.fetch');
        $maxBytes = (int) ($config['max_bytes'] ?? 10485760);

        for ($hop = 0; $hop <= (int) ($config['max_redirects'] ?? 3); $hop++) {
            [$host, $port, $address] = $this->validate($url);

            $headers = array_filter([
                'User-Agent' => (string) ($config['user_agent'] ?? 'StarterKnowledgeBot/1.0'),
                'Accept' => implode(', ', $accept).', */*;q=0.1',
                'If-None-Match' => $conditional['etag'] ?? null,
                'If-Modified-Since' => $conditional['last_modified'] ?? null,
            ]);

            try {
                $response = Http::withHeaders($headers)
                    ->withoutRedirecting()
                    ->connectTimeout((int) ($config['connect_timeout'] ?? 5))
                    ->timeout((int) ($config['timeout'] ?? 15))
                    ->withOptions([
                        // Connect to the validated address only...
                        'curl' => [CURLOPT_RESOLVE => [$host.':'.$port.':'.(str_contains($address, ':') ? '['.$address.']' : $address)]],
                        'on_headers' => function ($response) use ($maxBytes) {
                            $length = (int) ($response->getHeaderLine('Content-Length') ?: 0);

                            if ($length > $maxBytes) {
                                throw new FetchException((string) __('The resource is larger than the allowed size.'));
                            }
                        },
                        'progress' => function ($total, $downloaded) use ($maxBytes) {
                            if ($downloaded > $maxBytes) {
                                throw new FetchException((string) __('The resource is larger than the allowed size.'));
                            }
                        },
                    ])
                    ->get($url);
            } catch (ConnectionException $exception) {
                throw new FetchException((string) __('Could not connect to :host.', ['host' => $host]), null, true);
            }

            if (in_array($response->status(), [301, 302, 303, 307, 308], true)) {
                $location = $response->header('Location');

                if ($location === '') {
                    throw new FetchException((string) __('Redirect without a destination.'), $response->status());
                }

                $url = $this->absolute($location, $url);

                continue;
            }

            return $this->toResource($url, $response, $accept, $maxBytes);
        }

        throw new FetchException((string) __('Too many redirects.'));
    }

    /**
     * @param  list<string>  $accept
     */
    protected function toResource(string $url, Response $response, array $accept, int $maxBytes): FetchedResource
    {
        if ($response->status() === 304) {
            return new FetchedResource($url, 304, '', '', $response->header('ETag') ?: null, $response->header('Last-Modified') ?: null, true);
        }

        if ($response->failed()) {
            throw new FetchException((string) __('The server answered :status.', ['status' => $response->status()]), $response->status(), $response->status() === 429 || $response->serverError());
        }

        $mime = strtolower(trim(explode(';', $response->header('Content-Type') ?: 'application/octet-stream')[0]));
        $body = $response->body();

        if (strlen($body) > $maxBytes) {
            throw new FetchException((string) __('The resource is larger than the allowed size.'));
        }

        if (! in_array($mime, $accept, true)) {
            throw new FetchException((string) __('The content type :type is not accepted.', ['type' => $mime]), 415);
        }

        return new FetchedResource($url, $response->status(), $body, $mime, $response->header('ETag') ?: null, $response->header('Last-Modified') ?: null);
    }

    /**
     * Validate scheme, port, host lists and every resolved address.
     *
     * @return array{0: string, 1: int, 2: string} host, port and the address to connect to
     *
     * @throws BlockedUrlException
     */
    public function validate(string $url): array
    {
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower(trim((string) ($parts['host'] ?? ''), '[]'));

        if (! in_array($scheme, ['http', 'https'], true) || $host === '') {
            throw new BlockedUrlException((string) __('Only http and https URLs are allowed.'));
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new BlockedUrlException((string) __('URLs with credentials are not allowed.'));
        }

        $port = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));

        if (! in_array($port, (array) config('starter.rag.fetch.allowed_ports', [80, 443]), true)) {
            throw new BlockedUrlException((string) __('The port :port is not allowed.', ['port' => $port]));
        }

        foreach ((array) config('starter.rag.fetch.blocked_hosts', []) as $blocked) {
            if ($host === $blocked || Str::endsWith($host, '.'.$blocked)) {
                throw new BlockedUrlException((string) __('The host :host is blocked.', ['host' => $host]));
            }
        }

        $allowed = (array) config('starter.rag.fetch.allowed_hosts', []);

        if ($allowed !== [] && ! collect($allowed)->contains(fn (string $item) => $host === $item || Str::endsWith($host, '.'.$item))) {
            throw new BlockedUrlException((string) __('The host :host is not in the allowed list.', ['host' => $host]));
        }

        $addresses = $this->resolver->resolve($host);

        if ($addresses === []) {
            throw new BlockedUrlException((string) __('The host :host could not be resolved.', ['host' => $host]));
        }

        foreach ($addresses as $address) {
            if (! self::isPublic($address)) {
                throw new BlockedUrlException((string) __('The host :host resolves to a private or reserved address.', ['host' => $host]));
            }
        }

        return [$host, $port, $addresses[0]];
    }

    public static function isPublic(string $address): bool
    {
        if (! filter_var($address, FILTER_VALIDATE_IP)) {
            return false;
        }

        foreach (self::BLOCKED_RANGES as $range) {
            if (self::inRange($address, $range)) {
                return false;
            }
        }

        return (bool) filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
    }

    public static function inRange(string $address, string $cidr): bool
    {
        [$subnet, $bits] = explode('/', $cidr);
        $address = inet_pton($address);
        $subnet = inet_pton($subnet);

        if ($address === false || $subnet === false || strlen($address) !== strlen($subnet)) {
            return false;
        }

        $bits = (int) $bits;
        $bytes = intdiv($bits, 8);
        $remainder = $bits % 8;

        if (strncmp($address, $subnet, $bytes) !== 0) {
            return false;
        }

        if ($remainder === 0) {
            return true;
        }

        $mask = chr((0xFF << (8 - $remainder)) & 0xFF);

        return (ord($address[$bytes]) & ord($mask)) === (ord($subnet[$bytes]) & ord($mask));
    }

    /**
     * Resolve a (possibly relative) link against a base URL.
     */
    public function absolute(string $link, string $base): string
    {
        $link = trim($link);

        if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $link)) {
            return $link;
        }

        $parts = parse_url($base);
        $origin = ($parts['scheme'] ?? 'https').'://'.($parts['host'] ?? '').(isset($parts['port']) ? ':'.$parts['port'] : '');

        if (str_starts_with($link, '//')) {
            return ($parts['scheme'] ?? 'https').':'.$link;
        }

        if (str_starts_with($link, '/')) {
            return $origin.$link;
        }

        $path = $parts['path'] ?? '/';
        $directory = substr($path, 0, (int) strrpos($path, '/') + 1);
        $segments = [];

        foreach (explode('/', $directory.$link) as $segment) {
            if ($segment === '..') {
                array_pop($segments);
            } elseif ($segment !== '.') {
                $segments[] = $segment;
            }
        }

        return $origin.'/'.ltrim(implode('/', $segments), '/');
    }

    /**
     * Canonical form used as external id (no fragment, sorted query).
     */
    public static function normalizeUrl(string $url): string
    {
        $parts = parse_url(trim($url));

        if ($parts === false || ! isset($parts['host'])) {
            return trim($url);
        }

        $query = '';

        if (isset($parts['query'])) {
            parse_str($parts['query'], $params);
            ksort($params);
            $query = $params === [] ? '' : '?'.http_build_query($params);
        }

        $port = isset($parts['port']) && ! in_array((int) $parts['port'], [80, 443], true) ? ':'.$parts['port'] : '';

        return strtolower($parts['scheme'] ?? 'https').'://'.strtolower($parts['host']).$port.($parts['path'] ?? '/').$query;
    }
}
