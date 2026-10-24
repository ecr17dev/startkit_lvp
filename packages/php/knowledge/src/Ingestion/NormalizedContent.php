<?php

namespace Starter\Knowledge\Ingestion;

final readonly class NormalizedContent
{
    /**
     * @param  list<array{page: int, start: int, end: int}>|null  $pages  byte ranges of each page
     */
    public function __construct(
        public string $text,
        public string $hash,
        public ?array $pages,
    ) {}

    public function isEmpty(int $minimum = 20): bool
    {
        return mb_strlen(preg_replace('/\s+/u', '', $this->text) ?? '') < $minimum;
    }
}
