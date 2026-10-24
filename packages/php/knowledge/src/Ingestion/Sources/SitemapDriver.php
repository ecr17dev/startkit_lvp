<?php

namespace Starter\Knowledge\Ingestion\Sources;

use SimpleXMLElement;
use Starter\Knowledge\Ingestion\Fetch\BlockedUrlException;
use Starter\Knowledge\Ingestion\Fetch\FetchException;
use Starter\Knowledge\Ingestion\Fetch\SafeFetcher;
use Starter\Knowledge\Models\Source;
use Throwable;

/**
 * Pages listed by a sitemap (and a sitemap index, one level). <lastmod>
 * and ETags avoid downloading unchanged pages.
 */
class SitemapDriver implements SourceDriver
{
    public function __construct(protected SafeFetcher $fetcher) {}

    public function rules(): array
    {
        return [
            'config.url' => ['required', 'url:http,https', 'max:2048'],
            'config.max_pages' => ['nullable', 'integer', 'min:1', 'max:'.(int) config('starter.rag.fetch.max_pages', 200)],
            'config.path_prefix' => ['nullable', 'string', 'max:255', 'starts_with:/'],
        ];
    }

    public function discover(Source $source, SyncContext $context): iterable
    {
        $maxPages = min((int) ($source->config['max_pages'] ?? 100), (int) config('starter.rag.fetch.max_pages', 200));
        $prefix = $source->config['path_prefix'] ?? null;
        $entries = $this->entries((string) $source->config['url'], 0);
        $count = 0;

        foreach ($entries as [$url, $lastmod]) {
            $path = (string) parse_url($url, PHP_URL_PATH);

            if ($prefix && ! str_starts_with($path, $prefix)) {
                continue;
            }

            if ($count >= $maxPages) {
                $context->truncated = true;
                $context->warnings[] = (string) __('The page limit (:max) was reached; pages not visited are kept.', ['max' => $maxPages]);

                break;
            }

            $count++;
            $context->checkpoint();

            yield new SourceItem(
                externalId: $url,
                uri: $url,
                loader: function (array $conditional) use ($url) {
                    try {
                        $resource = $this->fetcher->fetch($url, SafeFetcher::DOCUMENT_TYPES, $conditional);
                    } catch (BlockedUrlException $exception) {
                        return RawContent::failed($exception->getMessage());
                    } catch (FetchException $exception) {
                        return RawContent::failed($exception->getMessage(), $exception->gone());
                    }

                    return new RawContent($resource->body, $resource->mimeType, basename((string) parse_url($url, PHP_URL_PATH)) ?: 'index.html', $resource->etag, $resource->lastModified, $resource->notModified);
                },
                metadata: array_filter(['lastmod' => $lastmod]),
                marker: $lastmod,
            );
        }
    }

    /**
     * @return list<array{0: string, 1: string|null}>
     */
    protected function entries(string $url, int $level): array
    {
        $resource = $this->fetcher->fetch($url, SafeFetcher::SITEMAP_TYPES);

        try {
            $xml = new SimpleXMLElement($resource->body, LIBXML_NONET | LIBXML_NOCDATA);
        } catch (Throwable) {
            throw new FetchException((string) __('The sitemap is not valid XML.'));
        }

        $entries = [];

        if ($xml->getName() === 'sitemapindex' && $level === 0) {
            foreach (array_slice(iterator_to_array($xml->children(), false), 0, 10) as $sitemap) {
                $entries = [...$entries, ...$this->entries(trim((string) $sitemap->loc), 1)];
            }

            return $entries;
        }

        foreach ($xml->children() as $entry) {
            $loc = trim((string) $entry->loc);

            if ($loc !== '') {
                $entries[] = [SafeFetcher::normalizeUrl($loc), trim((string) $entry->lastmod) ?: null];
            }
        }

        return $entries;
    }
}
