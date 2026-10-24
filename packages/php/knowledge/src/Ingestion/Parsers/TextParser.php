<?php

namespace Starter\Knowledge\Ingestion\Parsers;

use Starter\Knowledge\Ingestion\ParsedDocument;

class TextParser implements Parser
{
    public function mimeTypes(): array
    {
        return ['text/plain'];
    }

    public function parse(string $content, string $name, ?string $baseUrl = null): ParsedDocument
    {
        $title = pathinfo($name, PATHINFO_FILENAME) ?: $name;

        return new ParsedDocument('text', $title, [['text' => $content, 'page' => null]]);
    }
}
