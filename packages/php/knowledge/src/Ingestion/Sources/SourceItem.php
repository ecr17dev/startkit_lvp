<?php

namespace Starter\Knowledge\Ingestion\Sources;

use Closure;

/**
 * A document found while discovering a source. Its content is either
 * already read (crawls) or read on demand by the loader, which receives
 * the stored ETag/Last-Modified for conditional requests.
 */
final class SourceItem
{
    /**
     * @param  (Closure(array{etag?: string|null, last_modified?: string|null}): RawContent)|null  $loader
     * @param  list<string>|null  $acl
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public string $externalId,
        public ?string $title = null,
        public ?string $uri = null,
        public ?Closure $loader = null,
        public ?RawContent $content = null,
        public ?array $acl = null,
        public array $metadata = [],
        public ?string $marker = null,
    ) {}

    /**
     * @param  array{etag?: string|null, last_modified?: string|null}  $conditional
     */
    public function read(array $conditional = []): RawContent
    {
        if ($this->content !== null) {
            return $this->content;
        }

        return $this->loader !== null ? ($this->loader)($conditional) : RawContent::failed('No content.');
    }
}
