<?php

namespace Starter\Knowledge\Ingestion\Parsers;

use Starter\Knowledge\Ingestion\ParsedDocument;

/**
 * Keeps the Markdown (its headings drive the chunking) and moves a YAML
 * front matter, if any, to the metadata.
 */
class MarkdownParser implements Parser
{
    public function mimeTypes(): array
    {
        return ['text/markdown', 'text/x-markdown'];
    }

    public function parse(string $content, string $name, ?string $baseUrl = null): ParsedDocument
    {
        $metadata = [];

        if (preg_match('/\A---\R(.*?)\R---\R/s', $content, $front)) {
            $content = substr($content, strlen($front[0]));

            foreach (preg_split('/\R/', $front[1]) ?: [] as $line) {
                if (preg_match('/^([\w-]{1,40}):\s*(.+)$/', $line, $pair)) {
                    $metadata[strtolower($pair[1])] = trim($pair[2], " \"'");
                }
            }
        }

        $title = $metadata['title'] ?? null;

        if ($title === null && preg_match('/^#\s+(.+)$/m', $content, $heading)) {
            $title = trim($heading[1], " #\t");
        }

        return new ParsedDocument('markdown', $title ?? (pathinfo($name, PATHINFO_FILENAME) ?: $name), [['text' => $content, 'page' => null]], $metadata);
    }
}
