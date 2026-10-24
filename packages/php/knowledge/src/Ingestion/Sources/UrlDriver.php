<?php

namespace Starter\Knowledge\Ingestion\Sources;

use Starter\Knowledge\Ingestion\Fetch\BlockedUrlException;
use Starter\Knowledge\Ingestion\Fetch\FetchException;
use Starter\Knowledge\Ingestion\Fetch\SafeFetcher;
use Starter\Knowledge\Ingestion\Parsers\HtmlParser;
use Starter\Knowledge\Models\Source;

/**
 * Public web pages: the listed URLs and, up to the configured depth, the
 * links of the same host (and path prefix), within a maximum of pages.
 * Every request goes through the SSRF protected reader.
 */
class UrlDriver implements SourceDriver
{
    public function __construct(protected SafeFetcher $fetcher, protected HtmlParser $html) {}

    public function rules(): array
    {
        return [
            'config.urls' => ['required', 'array', 'min:1', 'max:50'],
            'config.urls.*' => ['required', 'url:http,https', 'max:2048'],
            'config.depth' => ['nullable', 'integer', 'min:0', 'max:'.(int) config('starter.rag.fetch.max_depth', 3)],
            'config.max_pages' => ['nullable', 'integer', 'min:1', 'max:'.(int) config('starter.rag.fetch.max_pages', 200)],
            'config.path_prefix' => ['nullable', 'string', 'max:255', 'starts_with:/'],
        ];
    }

    public function discover(Source $source, SyncContext $context): iterable
    {
        $depth = (int) ($source->config['depth'] ?? 0);
        $maxPages = min((int) ($source->config['max_pages'] ?? 50), (int) config('starter.rag.fetch.max_pages', 200));
        $prefix = $source->config['path_prefix'] ?? null;
        $queue = [];
        $seen = [];

        foreach ((array) ($source->config['urls'] ?? []) as $url) {
            $normalized = SafeFetcher::normalizeUrl((string) $url);
            $queue[] = [$normalized, 0];
            $seen[$normalized] = true;
        }

        $pages = 0;

        while ($queue !== []) {
            [$url, $level] = array_shift($queue);

            if ($pages >= $maxPages) {
                $context->truncated = true;
                $context->warnings[] = (string) __('The page limit (:max) was reached; pages not visited are kept.', ['max' => $maxPages]);

                break;
            }

            $context->checkpoint();
            $pages++;

            try {
                $resource = $this->fetcher->fetch($url);
            } catch (BlockedUrlException $exception) {
                yield new SourceItem($url, uri: $url, content: RawContent::failed($exception->getMessage()));

                continue;
            } catch (FetchException $exception) {
                yield new SourceItem($url, uri: $url, content: RawContent::failed($exception->getMessage(), $exception->gone()));

                continue;
            }

            $content = new RawContent($resource->body, $resource->mimeType, basename((string) parse_url($url, PHP_URL_PATH)) ?: 'index.html', $resource->etag, $resource->lastModified);

            if ($level < $depth && in_array($resource->mimeType, SafeFetcher::HTML_TYPES, true)) {
                $host = parse_url($url, PHP_URL_HOST);

                foreach ($this->html->parse($resource->body, $url)->links as $link) {
                    $absolute = SafeFetcher::normalizeUrl($this->fetcher->absolute($link, $resource->url));
                    $path = (string) parse_url($absolute, PHP_URL_PATH);

                    if (isset($seen[$absolute]) || parse_url($absolute, PHP_URL_HOST) !== $host || ($prefix && ! str_starts_with($path, $prefix))
                        || preg_match('/\.(png|jpe?g|gif|svg|webp|ico|css|js|zip|gz|mp[34]|woff2?)$/i', $path)) {
                        continue;
                    }

                    $seen[$absolute] = true;
                    $queue[] = [$absolute, $level + 1];
                }
            }

            yield new SourceItem($url, uri: $resource->url, content: $content, metadata: ['depth' => $level]);
        }
    }
}
