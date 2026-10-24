<?php

namespace Starter\Knowledge\Retrieval;

use Starter\Knowledge\Models\RetrievalRun;

final class RetrievalResult
{
    /**
     * @param  list<Hit>  $hits  ranked, within top-k and the context budget
     * @param  array<string, mixed>  $diagnostics  candidates per method, exact or approximate search...
     */
    public function __construct(
        public array $hits,
        public string $method,
        public int $latencyMs,
        public ?int $embeddingMs,
        public int $searchMs,
        public ?string $cost,
        public bool $costKnown,
        public int $contextTokens,
        public array $diagnostics = [],
        public ?RetrievalRun $run = null,
    ) {}

    public function isEmpty(): bool
    {
        return $this->hits === [];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(bool $withContent = true): array
    {
        return [
            'run_id' => $this->run?->id,
            'method' => $this->method,
            'hits' => array_map(fn (Hit $hit) => $hit->toArray($withContent), $this->hits),
            'latency_ms' => $this->latencyMs,
            'embedding_ms' => $this->embeddingMs,
            'search_ms' => $this->searchMs,
            'cost' => $this->costKnown ? $this->cost : null,
            'context_tokens' => $this->contextTokens,
            'diagnostics' => $this->diagnostics,
        ];
    }
}
