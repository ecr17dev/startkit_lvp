<?php

namespace Starter\Knowledge\Ingestion\Sources;

/**
 * Bytes read from an origin, or why they could not be read.
 */
final readonly class RawContent
{
    public function __construct(
        public string $bytes = '',
        public ?string $mimeType = null,
        public string $name = '',
        public ?string $etag = null,
        public ?string $lastModified = null,
        public bool $notModified = false,
        public ?string $error = null,
        public bool $gone = false,
        public ?string $storageDisk = null,
        public ?string $storagePath = null,
    ) {}

    public static function failed(string $error, bool $gone = false): self
    {
        return new self(error: $error, gone: $gone);
    }
}
