<?php

namespace Starter\Knowledge\Ingestion\Parsers;

/**
 * Parsers by MIME type. DOCX, spreadsheets or OCR are added here with
 * their own parser and tests.
 */
class ParserRegistry
{
    /**
     * @var array<string, Parser>
     */
    protected array $parsers = [];

    public function __construct()
    {
        foreach ([new TextParser, new MarkdownParser, new HtmlParser, new PdfParser] as $parser) {
            $this->register($parser);
        }
    }

    public function register(Parser $parser): void
    {
        foreach ($parser->mimeTypes() as $mime) {
            $this->parsers[$mime] = $parser;
        }
    }

    public function for(string $mime): ?Parser
    {
        return $this->parsers[strtolower($mime)] ?? null;
    }

    /**
     * @return list<string>
     */
    public function mimeTypes(): array
    {
        return array_keys($this->parsers);
    }

    /**
     * MIME type from the content (and the extension for text formats that
     * cannot be told apart by content).
     */
    public function detect(string $content, string $name, ?string $declared = null): string
    {
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));

        if (str_starts_with($content, '%PDF-')) {
            return 'application/pdf';
        }

        $detected = (new \finfo(FILEINFO_MIME_TYPE))->buffer($content) ?: 'application/octet-stream';

        if (str_starts_with($detected, 'text/') || $detected === 'application/x-empty') {
            if (in_array($extension, ['md', 'markdown'], true) || in_array($declared, ['text/markdown', 'text/x-markdown'], true)) {
                return 'text/markdown';
            }

            if ($detected === 'text/html' || in_array($extension, ['html', 'htm'], true) || $declared === 'text/html') {
                return 'text/html';
            }

            return 'text/plain';
        }

        return $detected;
    }
}
