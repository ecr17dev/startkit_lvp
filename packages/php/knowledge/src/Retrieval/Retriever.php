<?php

namespace Starter\Knowledge\Retrieval;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Starter\Ai\Enums\Feature;
use Starter\Knowledge\Embeddings\Embedder;
use Starter\Knowledge\Enums\Capability;
use Starter\Knowledge\Enums\SearchMethod;
use Starter\Knowledge\Models\Collection;
use Starter\Knowledge\Models\EmbeddingProfile;
use Starter\Knowledge\Models\IndexVersion;
use Starter\Knowledge\Models\RagConnection;
use Starter\Knowledge\Models\RetrievalRun;
use Starter\Knowledge\Store\ExternalApi\RetrievalApiClient;
use Starter\Knowledge\Store\ExternalApi\RetrievalApiException;
use Starter\Knowledge\Store\VectorStore;
use Starter\Knowledge\Support\Principals;

/**
 * Vector, full text and hybrid retrieval over the active index versions.
 *
 * - The access lists are applied inside each SQL query (chunk ACL overlaps
 *   the user principals), before anything leaves the database.
 * - The query is embedded with the exact profile of each active version.
 * - Hybrid search fuses the rankings with Reciprocal Rank Fusion
 *   (score = Σ 1 / (60 + rank)); similarities of different methods or
 *   profiles are never compared directly.
 * - Small sets are searched exactly; larger ones use HNSW with a wider
 *   candidate list (and iterative scans from pgvector 0.8) so filters do
 *   not starve the results.
 */
class Retriever
{
    public const RRF_K = 60;

    public function __construct(
        protected Embedder $embedder,
        protected VectorStore $store,
        protected RetrievalApiClient $api,
    ) {}

    public function search(RetrievalQuery $query): RetrievalResult
    {
        $started = hrtime(true);
        $principals = Principals::for($query->user);
        $collections = array_values(array_filter($query->collections, fn (Collection $collection) => $collection->isSearchable()
            && array_intersect($collection->acl, $principals) !== []));
        $managed = array_values(array_filter($collections, fn (Collection $collection) => ! $collection->isRemote()));
        $remote = array_values(array_filter($collections, fn (Collection $collection) => $collection->isRemote()));

        $text = Str::limit(trim($query->text), 2000, '');
        $limit = max($query->topK * 4, 20);
        $lists = [];
        $embeddingMs = null;
        $cost = null;
        $costKnown = true;
        $requestId = null;
        $diagnostics = ['candidates' => [], 'exact' => []];
        $searchMs = 0;

        if ($managed !== [] && $principals !== [] && $text !== '') {
            $versions = IndexVersion::query()->with('profile.connection', 'profile.aiModel')
                ->whereIn('id', array_map(fn (Collection $collection) => $collection->active_index_version_id, $managed))
                ->get();

            if ($query->method !== SearchMethod::Text) {
                foreach ($versions->groupBy('embedding_profile_id') as $group) {
                    /** @var EmbeddingProfile $profile */
                    $profile = $group->first()->profile;
                    $embedStart = hrtime(true);
                    $embedding = $this->embedder->embed($profile, [$text], Feature::Rag, $query->user, ['surface' => $query->surface]);
                    $embeddingMs = ($embeddingMs ?? 0) + (int) ((hrtime(true) - $embedStart) / 1_000_000);
                    $requestId ??= $embedding['request']->id;
                    $amount = $embedding['request']->cost;

                    if ($amount === null) {
                        $costKnown = false;
                    } else {
                        $cost = (string) BigDecimal::of($cost ?? '0')->plus((string) $amount)->toScale(10, RoundingMode::HalfUp);
                    }

                    $exact = $query->exact ?? ($profile->storage['exact'] ?? false) || $group->sum('chunks') < (int) config('starter.rag.exact_search_below', 20000);
                    $searchStart = hrtime(true);
                    $rows = $this->vectorSearch($profile, array_values(array_map('intval', $group->pluck('id')->all())), $embedding['vectors'][0], $principals, $query->sourceIds, $limit, $exact, $query->restrictPrincipals);
                    $searchMs += (int) ((hrtime(true) - $searchStart) / 1_000_000);

                    if ($query->minScore !== null) {
                        $rows = array_values(array_filter($rows, fn (Hit $hit) => $hit->score >= $query->minScore));
                    }

                    $diagnostics['candidates']['vector:'.$profile->id] = count($rows);
                    $diagnostics['exact'][$profile->id] = $exact;
                    $lists[] = $rows;
                }
            }

            if ($query->method !== SearchMethod::Vector) {
                // One query per data plane and text search configuration...
                $groups = [];

                foreach ($managed as $collection) {
                    $group = $collection->connection_id.'|'.$collection->textSearchConfig();
                    $groups[$group] ??= ['connection' => $collection->connection, 'config' => $collection->textSearchConfig(), 'versions' => []];
                    $groups[$group]['versions'][] = $collection->active_index_version_id;
                }

                foreach ($groups as ['connection' => $connection, 'config' => $config, 'versions' => $versionIds]) {
                    $searchStart = hrtime(true);
                    $rows = $this->textSearch($connection, $config, $versionIds, $text, $principals, $query->sourceIds, $limit, $query->restrictPrincipals);
                    $searchMs += (int) ((hrtime(true) - $searchStart) / 1_000_000);

                    if ($query->minTextRank !== null) {
                        $rows = array_values(array_filter($rows, fn (Hit $hit) => $hit->score >= $query->minTextRank));
                    }

                    $diagnostics['candidates']['text:'.$config.($connection->kind->value === 'internal' ? '' : '@'.$connection->id)] = count($rows);
                    $lists[] = $rows;
                }
            }
        }

        if ($remote !== [] && $principals !== [] && $text !== '') {
            foreach ($this->searchRemote($query, $remote, $text, $principals) as $answer) {
                $searchMs += $answer['ms'];
                $diagnostics['candidates']['api:'.$answer['connection']] = count($answer['hits']);

                if ($answer['error'] !== null) {
                    $diagnostics['errors'][] = $answer['error'];
                }

                // A service that does not report its price leaves the cost unknown...
                if ($answer['cost'] === null) {
                    $costKnown = false;
                } else {
                    $cost = (string) BigDecimal::of($cost ?? '0')->plus($answer['cost'])->toScale(10, RoundingMode::HalfUp);
                }

                $lists[] = $answer['hits'];
            }
        }

        $ranked = $this->fuse($lists, $query->method);
        [$hits, $contextTokens] = $this->budget($ranked, $query->topK, $query->maxContextTokens);
        $latency = (int) ((hrtime(true) - $started) / 1_000_000);

        // No embedding call (full text only) costs nothing...
        $result = new RetrievalResult($hits, $query->method->value, $latency, $embeddingMs, $searchMs, $costKnown ? ($cost ?? '0') : null, $costKnown, $contextTokens, $diagnostics);

        if ($query->record) {
            $result->run = $this->record($query, $collections, $result, $requestId);
        }

        return $result;
    }

    /**
     * @param  list<int>  $versionIds
     * @param  list<float>  $vector
     * @param  list<string>  $principals
     * @param  list<int>  $sourceIds
     * @param  list<string>|null  $restrict
     * @return list<Hit>
     */
    protected function vectorSearch(EmbeddingProfile $profile, array $versionIds, array $vector, array $principals, array $sourceIds, int $limit, bool $exact, ?array $restrict = null): array
    {
        $db = $this->store->db($profile->connection);
        $table = $profile->table();
        $dimensions = $profile->dimensions;
        $halfvec = ($profile->storage['index'] ?? null) === 'hnsw_halfvec';
        $column = $halfvec ? "e.embedding::halfvec({$dimensions})" : 'e.embedding';
        $parameter = $halfvec ? "?::halfvec({$dimensions})" : "?::vector({$dimensions})";
        $distance = "{$column} {$profile->metric->operator()} {$parameter}";
        $score = $profile->metric->scoreExpression($distance);
        $literal = $this->store->vectorLiteral($vector);

        return $db->transaction(function () use ($db, $table, $distance, $score, $literal, $versionIds, $principals, $sourceIds, $limit, $exact, $profile, $restrict) {
            if ($exact) {
                $db->statement('SET LOCAL enable_indexscan = off');
            } else {
                $db->statement('SET LOCAL hnsw.ef_search = '.max(40, min(1000, $limit * 4)));

                if (version_compare((string) ($profile->connection->pgvector_version ?? '0'), '0.8.0', '>=')) {
                    $db->statement("SET LOCAL hnsw.iterative_scan = 'relaxed_order'");
                }
            }

            $sources = $sourceIds !== [] ? ' AND c.source_id = ANY(?::bigint[])' : '';
            $restricted = $restrict !== null ? ' AND c.acl && ?::text[]' : '';
            $bindings = [$literal, '{'.implode(',', $versionIds).'}', $this->store->textArray($principals)];

            if ($sourceIds !== []) {
                $bindings[] = '{'.implode(',', $sourceIds).'}';
            }

            if ($restrict !== null) {
                $bindings[] = $this->store->textArray($restrict);
            }

            $bindings[] = $literal;
            $bindings[] = $limit;

            $rows = $db->select(
                "SELECT c.id, c.document_id, c.collection_id, c.source_id, c.content, c.token_count, c.metadata, {$score} AS score
                 FROM {$table} e JOIN rag_chunks c ON c.id = e.chunk_id
                 WHERE e.index_version_id = ANY(?::bigint[]) AND c.acl && ?::text[]{$sources}{$restricted}
                 ORDER BY {$distance} LIMIT ?",
                $bindings,
            );

            return array_values(array_map(fn (object $row) => $this->hit($row, $profile->metric->scoreType(), vector: true, connectionId: $profile->connection_id), $rows));
        });
    }

    /**
     * @param  list<int|null>  $versionIds
     * @param  list<string>  $principals
     * @param  list<int>  $sourceIds
     * @param  list<string>|null  $restrict
     * @return list<Hit>
     */
    protected function textSearch(RagConnection $connection, string $config, array $versionIds, string $text, array $principals, array $sourceIds, int $limit, ?array $restrict = null): array
    {
        $db = $this->store->db($connection);
        $sources = $sourceIds !== [] ? ' AND c.source_id = ANY(?::bigint[])' : '';
        $restricted = $restrict !== null ? ' AND c.acl && ?::text[]' : '';
        $bindings = [$config, $text, '{'.implode(',', array_filter($versionIds)).'}', $config, $this->store->textArray($principals)];

        if ($sourceIds !== []) {
            $bindings[] = '{'.implode(',', $sourceIds).'}';
        }

        if ($restrict !== null) {
            $bindings[] = $this->store->textArray($restrict);
        }

        $bindings[] = $limit;

        // websearch syntax with OR between terms: natural questions rarely
        // contain every word of a passage; ts_rank_cd rewards coverage...
        $rows = $db->select(
            "WITH query AS (SELECT replace(websearch_to_tsquery(CAST(? AS regconfig), ?)::text, ' & ', ' | ')::tsquery AS q)
             SELECT c.id, c.document_id, c.collection_id, c.source_id, c.content, c.token_count, c.metadata, ts_rank_cd(c.search, query.q, 32) AS score
             FROM rag_chunks c, query
             WHERE numnode(query.q) > 0 AND c.index_version_id = ANY(?::bigint[]) AND c.ts_config = CAST(? AS regconfig)
               AND c.search @@ query.q AND c.acl && ?::text[]{$sources}{$restricted}
             ORDER BY score DESC, c.id LIMIT ?",
            $bindings,
        );

        return array_values(array_map(fn (object $row) => $this->hit($row, 'text_rank', vector: false, connectionId: $connection->id), $rows));
    }

    protected function hit(object $row, string $scoreType, bool $vector, int $connectionId): Hit
    {
        $data = (array) $row;
        $score = (float) $data['score'];

        return new Hit(
            chunkId: (int) $data['id'],
            documentId: (int) $data['document_id'],
            collectionId: (int) $data['collection_id'],
            sourceId: (int) $data['source_id'],
            content: (string) $data['content'],
            tokenCount: (int) $data['token_count'],
            metadata: (array) json_decode((string) $data['metadata'], true),
            score: $score,
            scoreType: $scoreType,
            vectorScore: $vector ? $score : null,
            textScore: $vector ? null : $score,
            connectionId: $connectionId,
        );
    }

    /**
     * Search the remote collections, one call per retrieval API. The
     * service receives the user principals; without guaranteed isolation
     * only public collections are sent, and hits carrying an access list
     * are filtered again here.
     *
     * @param  list<Collection>  $collections
     * @param  list<string>  $principals
     * @return list<array{connection: int, hits: list<Hit>, ms: int, cost: string|null, error: string|null}>
     */
    protected function searchRemote(RetrievalQuery $query, array $collections, string $text, array $principals): array
    {
        $answers = [];

        foreach (collect($collections)->groupBy('connection_id') as $group) {
            /** @var Collection $first */
            $first = $group->first();
            $connection = $first->connection;
            $isolated = (bool) $connection->setting('enforces_acl', false);

            /** @var array<string, Collection> $byExternal */
            $byExternal = $group->filter(fn (Collection $collection) => $isolated || $collection->acl === ['everyone'])->keyBy('external_id')->all();

            if ($byExternal === [] || ! $connection->can(Capability::Query)) {
                continue;
            }

            $started = hrtime(true);

            try {
                $answer = $this->api->search($connection, $text, array_map('strval', array_keys($byExternal)), $query->topK, $principals, $query->user !== null ? (string) $query->user->getAuthIdentifier() : null, $query->method->value, $query->minScore);
                $error = null;
            } catch (RetrievalApiException $exception) {
                $answer = ['hits' => [], 'cost' => null];
                $error = 'api:'.$connection->id.': '.$exception->getMessage();
            }

            $hits = [];

            foreach ($answer['hits'] as $row) {
                $collection = $byExternal[$row['collection']] ?? (count($byExternal) === 1 ? reset($byExternal) : null);

                if ($collection === null || ($row['acl'] !== null && array_intersect($row['acl'], $principals) === [])) {
                    continue;
                }

                $hits[] = new Hit(
                    chunkId: 0,
                    documentId: 0,
                    collectionId: $collection->id,
                    sourceId: 0,
                    content: $row['text'],
                    tokenCount: $row['token_count'],
                    metadata: array_filter(['title' => $row['title'] !== '' ? $row['title'] : null, 'uri' => $row['uri'], 'location' => $row['location']]),
                    score: $row['score'],
                    scoreType: $row['score_type'],
                    connectionId: $connection->id,
                    externalId: $row['id'],
                    externalDocumentId: $row['document_id'],
                );
            }

            $answers[] = ['connection' => $connection->id, 'hits' => $hits, 'ms' => (int) ((hrtime(true) - $started) / 1_000_000), 'cost' => $error === null ? $answer['cost'] : null, 'error' => $error];
        }

        return $answers;
    }

    /**
     * One ranking keeps its scores; several are fused with RRF.
     *
     * @param  list<list<Hit>>  $lists
     * @return list<Hit>
     */
    protected function fuse(array $lists, SearchMethod $method): array
    {
        $lists = array_values(array_filter($lists, fn (array $list) => $list !== []));

        if ($lists === []) {
            return [];
        }

        if (count($lists) === 1) {
            return $lists[0];
        }

        /** @var array<string, Hit> $fused */
        $fused = [];
        $scores = [];

        foreach ($lists as $list) {
            foreach ($list as $position => $hit) {
                $id = $hit->key();
                $scores[$id] = ($scores[$id] ?? 0.0) + 1 / (self::RRF_K + $position + 1);

                if (! isset($fused[$id])) {
                    $fused[$id] = clone $hit;
                } else {
                    $fused[$id]->vectorScore ??= $hit->vectorScore;
                    $fused[$id]->textScore ??= $hit->textScore;
                }
            }
        }

        foreach ($fused as $id => $hit) {
            $hit->score = $scores[$id];
            $hit->scoreType = 'rrf';
        }

        $hits = array_values($fused);
        usort($hits, fn (Hit $a, Hit $b) => $b->score <=> $a->score ?: strcmp($a->key(), $b->key()));

        return $hits;
    }

    /**
     * Top-k within the context budget (a chunk larger than the remaining
     * budget is skipped, smaller ones may still fit).
     *
     * @param  list<Hit>  $ranked
     * @return array{0: list<Hit>, 1: int}
     */
    protected function budget(array $ranked, int $topK, int $maxTokens): array
    {
        $hits = [];
        $tokens = 0;

        foreach ($ranked as $hit) {
            if (count($hits) >= $topK) {
                break;
            }

            if ($hits !== [] && $tokens + $hit->tokenCount > $maxTokens) {
                continue;
            }

            $hit->rank = count($hits) + 1;
            $hits[] = $hit;
            $tokens += $hit->tokenCount;
        }

        return [$hits, $tokens];
    }

    /**
     * @param  list<Collection>  $collections
     */
    protected function record(RetrievalQuery $query, array $collections, RetrievalResult $result, ?string $requestId): RetrievalRun
    {
        return DB::transaction(function () use ($query, $collections, $result, $requestId) {
            $run = RetrievalRun::query()->create([
                'user_id' => $query->user?->getAuthIdentifier(),
                'agent_version_id' => $query->agentVersionId,
                'conversation_id' => $query->conversationId,
                'ai_request_id' => $requestId,
                'surface' => $query->surface,
                'query' => Str::limit($query->text, 4000, ''),
                'method' => $result->method,
                'settings' => [
                    'top_k' => $query->topK,
                    'min_score' => $query->minScore,
                    'min_text_rank' => $query->minTextRank,
                    'max_context_tokens' => $query->maxContextTokens,
                    'source_ids' => $query->sourceIds,
                    'exact' => $result->diagnostics['exact'] ?? [],
                    'candidates' => $result->diagnostics['candidates'] ?? [],
                ],
                'collection_ids' => array_map(fn (Collection $collection) => $collection->id, $collections),
                'hits_count' => count($result->hits),
                'context_tokens' => $result->contextTokens,
                'latency_ms' => $result->latencyMs,
                'embedding_ms' => $result->embeddingMs,
                'search_ms' => $result->searchMs,
                'cost' => $result->costKnown ? ($result->cost ?? '0') : null,
                'cost_known' => $result->costKnown,
                'outcome' => null,
            ]);

            foreach ($result->hits as $hit) {
                $run->hits()->create([
                    'rank' => $hit->rank,
                    'chunk_id' => $hit->chunkId,
                    'document_id' => $hit->isExternal() ? null : $hit->documentId,
                    'collection_id' => $hit->collectionId,
                    'score' => $hit->score,
                    'score_type' => $hit->scoreType,
                    'vector_score' => $hit->vectorScore,
                    'text_score' => $hit->textScore,
                    'token_count' => $hit->tokenCount,
                    'title' => Str::limit($hit->title(), 290, '…'),
                    'location' => array_filter(['section' => $hit->metadata['section'] ?? null, 'page_start' => $hit->metadata['page_start'] ?? null, 'page_end' => $hit->metadata['page_end'] ?? null, 'uri' => $hit->metadata['uri'] ?? null, 'text' => $hit->isExternal() ? $hit->location() : null, 'external_id' => $hit->externalId, 'external_document_id' => $hit->externalDocumentId]),
                ]);
            }

            return $run;
        });
    }
}
