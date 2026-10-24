<?php

namespace Starter\Knowledge\Ingestion\Parsers;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use Starter\Knowledge\Ingestion\ParsedDocument;

/**
 * Extracts the readable text of an HTML page as light Markdown (headings,
 * paragraphs, list items), dropping scripts, styles, navigation and forms.
 * Collects the links for crawling. Never executes anything.
 */
class HtmlParser implements Parser
{
    protected const SKIP = ['script', 'style', 'noscript', 'template', 'svg', 'canvas', 'iframe', 'object', 'embed', 'form', 'nav', 'footer', 'aside', 'button', 'select', 'input', 'textarea'];

    protected const BLOCKS = ['p', 'div', 'section', 'article', 'main', 'li', 'tr', 'blockquote', 'pre', 'dd', 'dt', 'figcaption', 'table', 'ul', 'ol', 'header'];

    public function mimeTypes(): array
    {
        return ['text/html', 'application/xhtml+xml'];
    }

    public function parse(string $content, string $name, ?string $baseUrl = null): ParsedDocument
    {
        $dom = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">'.$content, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_COMPACT);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $xpath = new DOMXPath($dom);
        $title = trim((string) $this->first($xpath, '//title')?->textContent);
        $metadata = [];

        foreach ($xpath->query('//meta[@name="description"]/@content') ?: [] as $attribute) {
            $metadata['description'] = trim((string) $attribute->nodeValue);
        }

        $canonical = $this->first($xpath, '//link[@rel="canonical"]/@href')?->nodeValue;

        if ($canonical !== null && $canonical !== '') {
            $metadata['canonical'] = trim($canonical);
        }

        $links = [];

        foreach ($xpath->query('//a[@href]') ?: [] as $anchor) {
            if ($anchor instanceof DOMElement) {
                $href = trim($anchor->getAttribute('href'));

                if ($href !== '' && ! str_starts_with($href, '#') && ! preg_match('/^(mailto|tel|javascript|data):/i', $href)) {
                    $links[] = $href;
                }
            }
        }

        $root = $this->first($xpath, '//main') ?? $this->first($xpath, '//article') ?? $dom->getElementsByTagName('body')->item(0) ?? $dom;
        $text = '';
        $this->walk($root, $text);

        if ($title === '' && preg_match('/^#\s+(.+)$/m', $text, $heading)) {
            $title = trim($heading[1]);
        }

        return new ParsedDocument('html', $title !== '' ? html_entity_decode($title) : ($name ?: null), [['text' => $text, 'page' => null]], $metadata, [], array_values(array_unique($links)));
    }

    protected function first(DOMXPath $xpath, string $expression): ?DOMNode
    {
        $nodes = $xpath->query($expression);

        $node = $nodes !== false ? $nodes->item(0) : null;

        return $node instanceof DOMNode ? $node : null;
    }

    protected function walk(DOMNode $node, string &$text): void
    {
        foreach ($node->childNodes as $child) {
            if ($child->nodeType === XML_TEXT_NODE) {
                $text .= preg_replace('/\s+/u', ' ', (string) $child->textContent);

                continue;
            }

            if (! $child instanceof DOMElement) {
                continue;
            }

            $tag = strtolower($child->tagName);

            if (in_array($tag, self::SKIP, true) || $child->getAttribute('hidden') !== '' || $child->getAttribute('aria-hidden') === 'true') {
                continue;
            }

            if (preg_match('/^h([1-6])$/', $tag, $level)) {
                $heading = trim(preg_replace('/\s+/u', ' ', $child->textContent) ?? '');

                if ($heading !== '') {
                    $text .= "\n\n".str_repeat('#', (int) $level[1]).' '.$heading."\n\n";
                }

                continue;
            }

            if ($tag === 'br') {
                $text .= "\n";

                continue;
            }

            $block = in_array($tag, self::BLOCKS, true);

            if ($block) {
                $text .= "\n\n";
            }

            if ($tag === 'li') {
                $text .= '- ';
            }

            $this->walk($child, $text);

            if ($block) {
                $text .= "\n\n";
            }
        }
    }
}
