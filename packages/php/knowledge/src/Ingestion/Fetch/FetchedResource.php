<?php

namespace Starter\Knowledge\Ingestion\Fetch;

final readonly class FetchedResource
{
    public function __construct(
        public string $url,
        public int $status,
        public string $body,
        public string $mimeType,
        public ?string $etag = null,
        public ?string $lastModified = null,
        public bool $notModified = false,
    ) {}
}
