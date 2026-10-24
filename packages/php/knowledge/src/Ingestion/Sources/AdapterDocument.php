<?php

namespace Starter\Knowledge\Ingestion\Sources;

final readonly class AdapterDocument
{
    /**
     * @param  list<string>|null  $acl  principals (null = the source access list)
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public string $id,
        public string $title,
        public string $text,
        public string $mimeType = 'text/markdown',
        public ?string $uri = null,
        public ?array $acl = null,
        public array $metadata = [],
        public ?string $version = null,
    ) {}
}
