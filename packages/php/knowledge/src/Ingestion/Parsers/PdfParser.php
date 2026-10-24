<?php

namespace Starter\Knowledge\Ingestion\Parsers;

use Smalot\PdfParser\Config;
use Smalot\PdfParser\Parser as Smalot;
use Starter\Knowledge\Ingestion\ParsedDocument;
use Throwable;

/**
 * Text of PDFs with extractable text, page by page. Scanned PDFs (images
 * without OCR) produce no useful text and are reported as empty, never as
 * correctly indexed.
 */
class PdfParser implements Parser
{
    public function mimeTypes(): array
    {
        return ['application/pdf'];
    }

    public function parse(string $content, string $name, ?string $baseUrl = null): ParsedDocument
    {
        $config = new Config;
        $config->setRetainImageContent(false);
        $config->setDecodeMemoryLimit(64 * 1024 * 1024);

        try {
            $pdf = (new Smalot([], $config))->parseContent($content);
        } catch (Throwable $exception) {
            throw new UnreadableDocumentException((string) __('The PDF could not be read: :error', ['error' => mb_substr($exception->getMessage(), 0, 200)]));
        }

        $segments = [];

        foreach ($pdf->getPages() as $index => $page) {
            try {
                $segments[] = ['text' => $page->getText(), 'page' => $index + 1];
            } catch (Throwable) {
                $segments[] = ['text' => '', 'page' => $index + 1];
            }
        }

        $details = $pdf->getDetails();
        $title = is_string($details['Title'] ?? null) && trim($details['Title']) !== '' ? trim($details['Title']) : (pathinfo($name, PATHINFO_FILENAME) ?: $name);

        return new ParsedDocument('pdf', $title, $segments, array_filter([
            'pages' => count($segments),
            'author' => is_string($details['Author'] ?? null) ? $details['Author'] : null,
        ]));
    }
}
