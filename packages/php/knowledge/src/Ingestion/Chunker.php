<?php

namespace Starter\Knowledge\Ingestion;

/**
 * Heading and paragraph aware chunking. Sizes are measured with an
 * identified estimate (≈ 4 characters per token, "chars/4"), not a model
 * tokenizer. Each chunk keeps its section path, byte offsets and pages so
 * citations can be rebuilt.
 */
class Chunker
{
    public const TOKEN_ESTIMATOR = 'chars/4';

    public static function tokens(string $text): int
    {
        return (int) ceil(mb_strlen($text) / 4);
    }

    /**
     * @param  array{target_tokens: int, overlap_tokens: int, max_tokens: int}  $config
     * @param  list<array{page: int, start: int, end: int}>|null  $pages
     * @return list<array{content: string, token_count: int, metadata: array<string, mixed>}>
     */
    public function chunk(string $text, array $config, ?array $pages = null): array
    {
        $target = max(50, (int) $config['target_tokens']);
        $max = max($target, (int) $config['max_tokens']);
        $overlap = max(0, min((int) $config['overlap_tokens'], intdiv($target, 2)));

        $chunks = [];
        $current = [];
        $tokens = 0;
        $section = [];
        $carry = null;

        foreach ($this->units($text, $max) as $unit) {
            if ($unit['heading']) {
                $chunks = $this->emit($chunks, $current, $carry, $section, $text, $pages);
                $carry = null;
                $current = $this->onlyHeadings($current) ? $current : [];
                $tokens = 0;
                $section = array_slice($section, 0, $unit['level'] - 1);
                $section[] = $unit['title'];
                $current[] = $unit;

                continue;
            }

            if ($tokens > 0 && $tokens + $unit['tokens'] > $target) {
                $chunks = $this->emit($chunks, $current, $carry, $section, $text, $pages);
                $carry = $overlap > 0 ? $this->overlapStart($text, $carry ?? $current[0]['start'], (int) end($current)['end'], $overlap) : null;
                $current = [];
                $tokens = 0;
            }

            $current[] = $unit;
            $tokens += $unit['tokens'];
        }

        return $this->emit($chunks, $current, $carry, $section, $text, $pages);
    }

    /**
     * @param  list<array{start: int, end: int, tokens: int, heading: bool, level: int, title: string}>  $units
     */
    protected function onlyHeadings(array $units): bool
    {
        foreach ($units as $unit) {
            if (! $unit['heading']) {
                return false;
            }
        }

        return true;
    }

    /**
     * Append the chunk made of the pending units (headings alone are kept
     * for the next chunk).
     *
     * @param  list<array{content: string, token_count: int, metadata: array<string, mixed>}>  $chunks
     * @param  list<array{start: int, end: int, tokens: int, heading: bool, level: int, title: string}>  $units
     * @param  list<string>  $section
     * @param  list<array{page: int, start: int, end: int}>|null  $pages
     * @return list<array{content: string, token_count: int, metadata: array<string, mixed>}>
     */
    protected function emit(array $chunks, array $units, ?int $carry, array $section, string $text, ?array $pages): array
    {
        if ($units === [] || $this->onlyHeadings($units)) {
            return $chunks;
        }

        $start = $carry ?? $units[0]['start'];
        $end = $units[count($units) - 1]['end'];
        $content = trim(substr($text, $start, $end - $start));

        if ($content === '') {
            return $chunks;
        }

        $chunks[] = [
            'content' => $content,
            'token_count' => self::tokens($content),
            'metadata' => array_filter([
                'section' => $section === [] ? null : implode(' › ', $section),
                'start' => $start,
                'end' => $end,
                ...$this->pageRange($pages, $start, $end),
            ], fn ($value) => $value !== null),
        ];

        return $chunks;
    }

    /**
     * Blocks separated by blank lines; headings are their own unit and
     * blocks above the maximum are split by sentences, then by words.
     *
     * @return list<array{start: int, end: int, tokens: int, heading: bool, level: int, title: string}>
     */
    protected function units(string $text, int $maxTokens): array
    {
        $units = [];

        preg_match_all('/\S(?:.*?)(?=\n\s*\n|\z)/s', $text, $matches, PREG_OFFSET_CAPTURE);

        foreach ($matches[0] as [$block, $offset]) {
            $block = rtrim($block);

            if (preg_match('/^(#{1,6})\s+(.+)$/', $block, $heading) && ! str_contains($block, "\n")) {
                $units[] = ['start' => $offset, 'end' => $offset + strlen($block), 'tokens' => self::tokens($block), 'heading' => true, 'level' => strlen($heading[1]), 'title' => trim($heading[2], " #\t")];

                continue;
            }

            foreach ($this->split($block, $offset, $maxTokens) as [$start, $end]) {
                $units[] = ['start' => $start, 'end' => $end, 'tokens' => self::tokens(substr($text, $start, $end - $start)), 'heading' => false, 'level' => 0, 'title' => ''];
            }
        }

        return $units;
    }

    /**
     * @return list<array{0: int, 1: int}> byte ranges
     */
    protected function split(string $block, int $offset, int $maxTokens): array
    {
        if (self::tokens($block) <= $maxTokens) {
            return [[$offset, $offset + strlen($block)]];
        }

        $ranges = [];
        $maxBytes = $maxTokens * 4;
        $pieces = preg_split('/(?<=[.!?…])\s+/u', $block, -1, PREG_SPLIT_OFFSET_CAPTURE) ?: [[$block, 0]];
        $start = null;
        $end = 0;

        foreach ($pieces as [$sentence, $position]) {
            $sentenceEnd = $position + strlen($sentence);

            if ($start !== null && $sentenceEnd - $start > $maxBytes) {
                $ranges[] = [$offset + $start, $offset + $end];
                $start = null;
            }

            if (strlen($sentence) > $maxBytes) {
                // A sentence longer than the maximum: cut at word boundaries...
                $cursor = $position;

                while ($cursor < $sentenceEnd) {
                    $length = min($maxBytes, $sentenceEnd - $cursor);
                    $piece = mb_strcut($block, $cursor, $length);

                    if ($cursor + strlen($piece) < $sentenceEnd && ($space = strrpos($piece, ' ')) !== false && $space > $length / 2) {
                        $piece = substr($piece, 0, $space);
                    }

                    $ranges[] = [$offset + $cursor, $offset + $cursor + strlen($piece)];
                    $cursor += max(1, strlen($piece));

                    while ($cursor < $sentenceEnd && $block[$cursor] === ' ') {
                        $cursor++;
                    }
                }

                continue;
            }

            $start ??= $position;
            $end = $sentenceEnd;
        }

        if ($start !== null) {
            $ranges[] = [$offset + $start, $offset + $end];
        }

        return $ranges;
    }

    /**
     * Start of the overlap carried into the next chunk, at a word boundary.
     */
    protected function overlapStart(string $text, int $start, int $end, int $overlapTokens): ?int
    {
        $bytes = $overlapTokens * 4;

        if ($end - $start <= $bytes) {
            return null;
        }

        $candidate = $end - $bytes;
        $space = strpos($text, ' ', $candidate);

        return $space !== false && $space < $end ? $space + 1 : null;
    }

    /**
     * @param  list<array{page: int, start: int, end: int}>|null  $pages
     * @return array{page_start?: int, page_end?: int}
     */
    protected function pageRange(?array $pages, int $start, int $end): array
    {
        if ($pages === null) {
            return [];
        }

        $first = null;
        $last = null;

        foreach ($pages as $page) {
            if ($page['end'] > $start && $page['start'] < $end) {
                $first ??= $page['page'];
                $last = $page['page'];
            }
        }

        return $first === null ? [] : ['page_start' => $first, 'page_end' => $last];
    }
}
