<?php

namespace Starter\Knowledge\Ingestion;

use Normalizer as IntlNormalizer;

/**
 * Normalizes extracted text: valid UTF-8 (NFC when intl is available),
 * no control characters, unified line endings and whitespace. Segments
 * keep their byte ranges so pages can be cited.
 */
class Normalizer
{
    public function normalize(ParsedDocument $document, int $maxCharacters = 2000000): NormalizedContent
    {
        $text = '';
        $pages = [];
        $paged = false;

        foreach ($document->segments as $segment) {
            $clean = $this->clean($segment['text']);

            if ($clean === '') {
                continue;
            }

            if ($text !== '') {
                $text .= "\n\n";
            }

            $start = strlen($text);
            $text .= $clean;

            if ($segment['page'] !== null) {
                $paged = true;
                $pages[] = ['page' => $segment['page'], 'start' => $start, 'end' => strlen($text)];
            }
        }

        if (mb_strlen($text) > $maxCharacters) {
            $text = mb_substr($text, 0, $maxCharacters);
            $document->warnings[] = 'Content truncated to the maximum document size.';
        }

        return new NormalizedContent($text, hash('sha256', $text), $paged ? $pages : null);
    }

    public function clean(string $text): string
    {
        if (! mb_check_encoding($text, 'UTF-8')) {
            $text = mb_convert_encoding($text, 'UTF-8', 'Windows-1252');
        }

        if (class_exists(IntlNormalizer::class)) {
            $text = IntlNormalizer::normalize($text, IntlNormalizer::FORM_C) ?: $text;
        }

        $text = str_replace(["\r\n", "\r", "\u{00A0}", "\u{FEFF}"], ["\n", "\n", ' ', ''], $text);
        $text = preg_replace('/[^\P{C}\n\t]/u', '', $text) ?? $text;
        $text = preg_replace('/[ \t]+/u', ' ', $text) ?? $text;
        $text = preg_replace('/ *\n */u', "\n", $text) ?? $text;
        $text = preg_replace("/\n{3,}/u", "\n\n", $text) ?? $text;

        return trim($text);
    }
}
