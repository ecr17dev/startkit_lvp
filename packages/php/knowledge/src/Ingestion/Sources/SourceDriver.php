<?php

namespace Starter\Knowledge\Ingestion\Sources;

use Starter\Knowledge\Models\Source;

interface SourceDriver
{
    /**
     * Validation rules of the driver configuration (prefixed by "config.").
     *
     * @return array<string, mixed>
     */
    public function rules(): array;

    /**
     * @return iterable<SourceItem>
     */
    public function discover(Source $source, SyncContext $context): iterable;
}
