<?php

namespace Starter\Knowledge\Pipeline;

use Starter\Knowledge\Models\Document;
use Starter\Knowledge\Models\DocumentVersion;

final readonly class IngestOutcome
{
    public const CHANGED = 'changed';

    public const UNCHANGED = 'unchanged';

    public const EMPTY = 'empty';

    public const DUPLICATE = 'duplicate';

    public const FAILED = 'failed';

    public const GONE = 'gone';

    public function __construct(
        public string $status,
        public Document $document,
        public ?DocumentVersion $version = null,
    ) {}
}
