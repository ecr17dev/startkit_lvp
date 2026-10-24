<?php

namespace Starter\Knowledge\Ingestion\Parsers;

use Starter\Knowledge\Ingestion\ParsedDocument;

interface Parser
{
    /**
     * @return list<string> MIME types handled
     */
    public function mimeTypes(): array;

    public function parse(string $content, string $name, ?string $baseUrl = null): ParsedDocument;
}
