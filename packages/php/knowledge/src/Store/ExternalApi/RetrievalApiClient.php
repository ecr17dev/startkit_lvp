<?php

namespace Starter\Knowledge\Store\ExternalApi;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Starter\Knowledge\Models\RagConnection;
use Starter\Knowledge\Store\ExternalDatabases;

/**
 * Client of the retrieval API contract (version 1), relative to the base
 * URL of the connection:
 *
 * - GET  capabilities                  {version, capabilities[], enforces_acl}
 * - GET  collections                   {data: [{id, name, description?, documents?}]}
 * - GET  collections/{id}/documents    {data: [{id, title, uri?, updated_at?}], next_page?}
 * - POST search                        {query, collections[], top_k, method?, min_score?, principals[], user}
 *                                      → {data: {hits: [{id, document_id, collection, title, text, uri?, location?, score, score_type?, token_count?, acl?}]}, usage?: {cost?}}
 *
 * Every response is validated and bounded; the token never appears in an
 * error. Redirects are not followed.
 */
class RetrievalApiClient
{
    public const VERSION = '1';

    /**
     * Operations this adapter implements on top of the contract.
     */
    public const SUPPORTED = ['query', 'list_collections', 'list_documents'];

    public const MAX_TEXT = 20000;

    /**
     * @return array{version: string, capabilities: list<string>, enforces_acl: bool}
     */
    public function capabilities(RagConnection $connection): array
    {
        $data = $this->json($this->guard(fn () => $this->request($connection)->get('capabilities')));
        $declared = array_values(array_filter((array) ($data['capabilities'] ?? []), 'is_string'));

        return [
            'version' => (string) ($data['version'] ?? ''),
            'capabilities' => array_values(array_intersect(self::SUPPORTED, $declared)),
            'enforces_acl' => (bool) ($data['enforces_acl'] ?? false),
        ];
    }

    /**
     * @return list<array{id: string, name: string, description: string|null, documents: int|null}>
     */
    public function collections(RagConnection $connection): array
    {
        $data = $this->json($this->guard(fn () => $this->request($connection)->get('collections')));

        return array_values(array_map(fn (array $row) => [
            'id' => Str::limit((string) $row['id'], 200, ''),
            'name' => Str::limit((string) ($row['name'] ?? $row['id']), 120, ''),
            'description' => isset($row['description']) ? Str::limit((string) $row['description'], 1000, '') : null,
            'documents' => isset($row['documents']) ? (int) $row['documents'] : null,
        ], array_slice(array_filter((array) ($data['data'] ?? []), fn ($row) => is_array($row) && isset($row['id']) && is_scalar($row['id'])), 0, 500)));
    }

    /**
     * @return array{data: list<array{id: string, title: string, uri: string|null, updated_at: string|null}>, next_page: int|null}
     */
    public function documents(RagConnection $connection, string $collection, int $page = 1): array
    {
        $data = $this->json($this->guard(fn () => $this->request($connection)->get('collections/'.rawurlencode($collection).'/documents', ['page' => max(1, $page)])));

        return [
            'data' => array_values(array_map(fn (array $row) => [
                'id' => Str::limit((string) $row['id'], 200, ''),
                'title' => Str::limit((string) ($row['title'] ?? $row['id']), 300, ''),
                'uri' => isset($row['uri']) && is_string($row['uri']) ? Str::limit($row['uri'], 2048, '') : null,
                'updated_at' => isset($row['updated_at']) && is_string($row['updated_at']) ? $row['updated_at'] : null,
            ], array_slice(array_filter((array) ($data['data'] ?? []), fn ($row) => is_array($row) && isset($row['id']) && is_scalar($row['id'])), 0, 200))),
            'next_page' => isset($data['next_page']) && is_numeric($data['next_page']) ? (int) $data['next_page'] : null,
        ];
    }

    /**
     * @param  list<string>  $collections  remote collection ids
     * @param  list<string>  $principals  of the user (the service filters with them)
     * @return array{hits: list<array{id: string, document_id: string|null, collection: string, title: string, text: string, uri: string|null, location: string|null, score: float, score_type: string, token_count: int, acl: list<string>|null}>, cost: string|null}
     */
    public function search(RagConnection $connection, string $query, array $collections, int $topK, array $principals, ?string $userId, ?string $method = null, ?float $minScore = null): array
    {
        $data = $this->json($this->guard(fn () => $this->request($connection)->post('search', array_filter([
            'query' => $query,
            'collections' => $collections,
            'top_k' => $topK,
            'method' => $method,
            'min_score' => $minScore,
            'principals' => $principals,
            'user' => ['id' => $userId],
        ], fn ($value) => $value !== null))));

        $rows = array_filter((array) ($data['data']['hits'] ?? []), fn ($row) => is_array($row) && isset($row['id'], $row['text']) && is_scalar($row['id']) && is_string($row['text']));
        $cost = $data['usage']['cost'] ?? null;

        return [
            'hits' => array_values(array_map(function (array $row) {
                $text = Str::limit($row['text'], self::MAX_TEXT, '…');

                return [
                    'id' => Str::limit((string) $row['id'], 200, ''),
                    'document_id' => isset($row['document_id']) && is_scalar($row['document_id']) ? Str::limit((string) $row['document_id'], 200, '') : null,
                    'collection' => (string) ($row['collection'] ?? ''),
                    'title' => Str::limit((string) ($row['title'] ?? ''), 300, ''),
                    'text' => $text,
                    'uri' => isset($row['uri']) && is_string($row['uri']) && preg_match('#^https?://#i', $row['uri']) ? Str::limit($row['uri'], 2048, '') : null,
                    'location' => isset($row['location']) && is_string($row['location']) ? Str::limit($row['location'], 300, '') : null,
                    'score' => is_numeric($row['score'] ?? null) ? (float) $row['score'] : 0.0,
                    'score_type' => preg_match('/^[a-z_]{1,24}$/', (string) ($row['score_type'] ?? '')) ? (string) $row['score_type'] : 'remote',
                    'token_count' => isset($row['token_count']) && is_numeric($row['token_count']) ? (int) $row['token_count'] : (int) ceil(mb_strlen($text) / 4),
                    'acl' => isset($row['acl']) && is_array($row['acl']) ? array_values(array_filter($row['acl'], 'is_string')) : null,
                ];
            }, array_slice($rows, 0, max(1, $topK) * 2))),
            'cost' => is_numeric($cost) && (float) $cost >= 0 ? (string) $cost : null,
        ];
    }

    public function request(RagConnection $connection): PendingRequest
    {
        $timeout = max(1, min(60, (int) $connection->setting('timeout', 10)));
        $token = ExternalDatabases::secret($connection->credentials['token'] ?? null);

        return Http::baseUrl(rtrim((string) $connection->setting('base_url'), '/').'/')
            ->acceptJson()
            ->asJson()
            ->timeout($timeout)
            ->connectTimeout(min(5, $timeout))
            ->withUserAgent(config('app.name').' knowledge')
            ->withOptions(['allow_redirects' => false])
            ->when($token !== null, fn (PendingRequest $request) => $request->withToken((string) $token));
    }

    /**
     * @return array<string, mixed>
     *
     * @throws RetrievalApiException
     */
    protected function json(Response $response): array
    {
        if (! $response->successful()) {
            throw new RetrievalApiException((string) __('The retrieval API answered :status.', ['status' => $response->status()]));
        }

        $data = $response->json();

        if (! is_array($data)) {
            throw new RetrievalApiException((string) __('The retrieval API did not answer valid JSON.'));
        }

        return $data;
    }

    /**
     * Wrap transport failures without leaking the request.
     *
     * @param  \Closure(): Response  $callback
     *
     * @throws RetrievalApiException
     */
    protected function guard(\Closure $callback): Response
    {
        try {
            return $callback();
        } catch (ConnectionException $exception) {
            throw new RetrievalApiException((string) __('The retrieval API could not be reached (:error).', ['error' => Str::limit(preg_replace('/Bearer\s+\S+/i', 'Bearer ***', $exception->getMessage()) ?? '', 200)]));
        }
    }
}
