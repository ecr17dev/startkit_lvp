<?php

namespace Starter\Knowledge\Retrieval;

use Illuminate\Contracts\Auth\Authenticatable;
use Starter\Knowledge\Enums\SearchMethod;
use Starter\Knowledge\Models\Collection;

/**
 * A search request. The collections must already be authorized for the
 * user; the retriever applies the access lists again inside the SQL.
 */
final class RetrievalQuery
{
    /**
     * @param  list<Collection>  $collections
     * @param  list<int>  $sourceIds  optional filter
     */
    public function __construct(
        public string $text,
        public ?Authenticatable $user,
        public array $collections,
        public SearchMethod $method = SearchMethod::Hybrid,
        public int $topK = 6,
        public ?float $minScore = null,
        public ?float $minTextRank = null,
        public int $maxContextTokens = 2000,
        public string $surface = 'api',
        public array $sourceIds = [],
        public ?int $agentVersionId = null,
        public ?string $conversationId = null,
        public bool $record = true,
        public ?bool $exact = null,
    ) {}

    /**
     * Settings of the first collection, overridden by explicit values.
     *
     * @param  list<Collection>  $collections
     * @param  array{method?: string|null, top_k?: int|null, min_score?: float|null, min_text_rank?: float|null, max_context_tokens?: int|null}  $overrides
     */
    public static function for(string $text, ?Authenticatable $user, array $collections, array $overrides = [], string $surface = 'api'): self
    {
        $defaults = [...Collection::defaultSearch(), ...(isset($collections[0]) ? $collections[0]->search : [])];

        return new self(
            text: $text,
            user: $user,
            collections: $collections,
            method: SearchMethod::tryFrom((string) ($overrides['method'] ?? $defaults['method'])) ?? SearchMethod::Hybrid,
            topK: max(1, min(50, (int) ($overrides['top_k'] ?? $defaults['top_k']))),
            minScore: isset($overrides['min_score']) ? (float) $overrides['min_score'] : $defaults['min_score'],
            minTextRank: isset($overrides['min_text_rank']) ? (float) $overrides['min_text_rank'] : $defaults['min_text_rank'],
            maxContextTokens: max(100, (int) ($overrides['max_context_tokens'] ?? $defaults['max_context_tokens'])),
            surface: $surface,
        );
    }
}
