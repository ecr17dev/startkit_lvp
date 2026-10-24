<?php

namespace Starter\Knowledge\Retrieval;

/**
 * A retrieved chunk. Scores are rankings within a method: cosine
 * similarity, text rank or reciprocal rank fusion, not probabilities.
 */
final class Hit
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public int $chunkId,
        public int $documentId,
        public int $collectionId,
        public int $sourceId,
        public string $content,
        public int $tokenCount,
        public array $metadata,
        public float $score,
        public string $scoreType,
        public ?float $vectorScore = null,
        public ?float $textScore = null,
        public int $rank = 0,
        public int $connectionId = 0,
    ) {}

    /**
     * Identity across connections (chunk ids are per data plane).
     */
    public function key(): string
    {
        return $this->connectionId.':'.$this->chunkId;
    }

    public function title(): string
    {
        return (string) ($this->metadata['title'] ?? (string) __('Document #:id', ['id' => $this->documentId]));
    }

    /**
     * Human location: section and pages.
     */
    public function location(): ?string
    {
        $parts = array_filter([
            self::section($this->metadata['section'] ?? null, $this->metadata['title'] ?? null),
            isset($this->metadata['page_start'])
                ? ($this->metadata['page_start'] === ($this->metadata['page_end'] ?? null)
                    ? (string) __('page :n', ['n' => $this->metadata['page_start']])
                    : (string) __('pages :from-:to', ['from' => $this->metadata['page_start'], 'to' => $this->metadata['page_end'] ?? '']))
                : null,
        ]);

        return $parts === [] ? null : implode(' · ', $parts);
    }

    /**
     * Section path without the document title (usually its first heading).
     */
    public static function section(mixed $section, mixed $title): ?string
    {
        if (! is_string($section) || $section === '') {
            return null;
        }

        if (is_string($title) && $title !== '') {
            if ($section === $title) {
                return null;
            }

            if (str_starts_with($section, $title.' › ')) {
                return substr($section, strlen($title.' › '));
            }
        }

        return $section;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(bool $withContent = true): array
    {
        return array_filter([
            'rank' => $this->rank,
            'chunk_id' => $this->chunkId,
            'document_id' => $this->documentId,
            'collection_id' => $this->collectionId,
            'source_id' => $this->sourceId,
            'title' => $this->title(),
            'location' => $this->location(),
            'uri' => $this->metadata['uri'] ?? null,
            'page_start' => $this->metadata['page_start'] ?? null,
            'page_end' => $this->metadata['page_end'] ?? null,
            'section' => $this->metadata['section'] ?? null,
            'score' => round($this->score, 6),
            'score_type' => $this->scoreType,
            'vector_score' => $this->vectorScore !== null ? round($this->vectorScore, 6) : null,
            'text_score' => $this->textScore !== null ? round($this->textScore, 6) : null,
            'token_count' => $this->tokenCount,
            'content' => $withContent ? $this->content : null,
        ], fn ($value) => $value !== null);
    }
}
