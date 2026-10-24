<?php

namespace Starter\Knowledge\Ingestion;

/**
 * Text extracted by a parser, split in segments (pages for PDFs) so the
 * normalized content keeps the location of every citation.
 */
final class ParsedDocument
{
    /**
     * @param  list<array{text: string, page: int|null}>  $segments
     * @param  array<string, mixed>  $metadata
     * @param  list<string>  $warnings
     * @param  list<string>  $links  absolute links found (HTML), for crawling
     */
    public function __construct(
        public string $parser,
        public ?string $title,
        public array $segments,
        public array $metadata = [],
        public array $warnings = [],
        public array $links = [],
    ) {}
}
