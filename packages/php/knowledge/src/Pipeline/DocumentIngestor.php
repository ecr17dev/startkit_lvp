<?php

namespace Starter\Knowledge\Pipeline;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Starter\Knowledge\Enums\DocumentStatus;
use Starter\Knowledge\Enums\JobStage;
use Starter\Knowledge\Ingestion\Normalizer;
use Starter\Knowledge\Ingestion\Parsers\ParserRegistry;
use Starter\Knowledge\Ingestion\Parsers\UnreadableDocumentException;
use Starter\Knowledge\Ingestion\Sources\RawContent;
use Starter\Knowledge\Ingestion\Sources\SourceItem;
use Starter\Knowledge\Models\Document;
use Starter\Knowledge\Models\Source;
use Throwable;

/**
 * Turns a discovered item into a document version: read (conditionally),
 * validate size and real type, extract, normalize and deduplicate. Only
 * new content creates a version; the logical document is unique per
 * source and external id, so retries never duplicate it.
 */
class DocumentIngestor
{
    public function __construct(
        protected ParserRegistry $parsers,
        protected Normalizer $normalizer,
    ) {}

    public function ingest(Source $source, SourceItem $item, JobContext $context, bool $force = false): IngestOutcome
    {
        $document = Document::query()->firstOrCreate(
            ['source_id' => $source->id, 'external_hash' => Document::hashExternalId($item->externalId)],
            ['collection_id' => $source->collection_id, 'external_id' => $item->externalId, 'status' => DocumentStatus::Pending, 'title' => $item->title, 'uri' => $item->uri],
        );

        $document->forceFill(['seen_at' => now(), 'deleted_at' => null]);

        // The origin marker (sitemap lastmod, adapter version, file hash) did not change...
        if (! $force && $item->marker !== null && $item->marker !== '' && $document->status === DocumentStatus::Indexed
            && ($document->metadata['marker'] ?? null) === $item->marker) {
            $document->save();

            return new IngestOutcome(IngestOutcome::UNCHANGED, $document);
        }

        $raw = $context->stage(JobStage::Fetch, fn () => $item->read($force ? [] : ['etag' => $document->etag, 'last_modified' => $document->last_modified]));

        if ($raw->notModified && $document->status === DocumentStatus::Indexed) {
            $document->save();

            return new IngestOutcome(IngestOutcome::UNCHANGED, $document);
        }

        if ($raw->error !== null) {
            $document->save();

            if ($raw->gone) {
                return new IngestOutcome(IngestOutcome::GONE, $document);
            }

            return $this->fail($document, $raw->error);
        }

        try {
            $mime = $context->stage(JobStage::Validate, fn () => $this->validate($raw));
            $parsed = $context->stage(JobStage::Extract, fn () => $this->parsers->for($mime)?->parse($raw->bytes, $raw->name ?: (string) $item->title, $item->uri));
        } catch (UnreadableDocumentException $exception) {
            return $this->fail($document, $exception->getMessage());
        } catch (Throwable $exception) {
            return $this->fail($document, $this->sanitize($exception));
        }

        if ($parsed === null) {
            return $this->fail($document, (string) __('The content type :type is not supported.', ['type' => $mime]));
        }

        $normalized = $context->stage(JobStage::Normalize, fn () => $this->normalizer->normalize($parsed, (int) config('starter.rag.limits.max_document_chars', 2000000)));
        $title = Str::limit($item->title ?: ($parsed->title ?: $document->external_id), 290, '…');

        $document->fill([
            'title' => $title,
            'uri' => $item->uri ?? $document->uri,
            'mime_type' => $mime,
            'size' => strlen($raw->bytes),
            'etag' => $raw->etag,
            'last_modified' => $raw->lastModified,
            'acl' => $item->acl,
            'metadata' => array_filter([...$item->metadata, ...$parsed->metadata, 'marker' => $item->marker], fn ($value) => $value !== null && $value !== ''),
        ]);

        if ($normalized->isEmpty()) {
            $document->forceFill([
                'status' => DocumentStatus::Empty,
                'error' => $parsed->parser === 'pdf'
                    ? (string) __('No extractable text (probably a scanned PDF; OCR is not enabled).')
                    : (string) __('The document has no useful text.'),
            ])->save();

            return new IngestOutcome(IngestOutcome::EMPTY, $document);
        }

        return $context->stage(JobStage::Dedupe, function () use ($document, $normalized, $parsed, $raw, $mime, $force, $source) {
            if (! $force && $document->content_hash === $normalized->hash && in_array($document->status, [DocumentStatus::Indexed, DocumentStatus::Indexing], true)) {
                $document->save();

                return new IngestOutcome(IngestOutcome::UNCHANGED, $document);
            }

            $twin = Document::query()
                ->where('source_id', $source->id)
                ->whereKeyNot($document->id)
                ->alive()
                ->where('content_hash', $normalized->hash)
                ->whereIn('status', [DocumentStatus::Indexed->value, DocumentStatus::Indexing->value, DocumentStatus::Pending->value])
                ->first();

            if ($twin !== null) {
                $document->forceFill([
                    'status' => DocumentStatus::Duplicate,
                    'error' => null,
                    'metadata' => [...($document->metadata ?? []), 'duplicate_of' => $twin->id],
                ])->save();

                return new IngestOutcome(IngestOutcome::DUPLICATE, $document);
            }

            return DB::transaction(function () use ($document, $normalized, $parsed, $raw, $mime) {
                $number = (int) $document->versions()->max('number') + 1;
                [$disk, $path] = $this->storeOriginal($document, $raw, $number, $mime);

                $version = $document->versions()->create([
                    'number' => $number,
                    'content_hash' => $normalized->hash,
                    'parser' => $parsed->parser,
                    'mime_type' => $mime,
                    'size' => strlen($raw->bytes),
                    'storage_disk' => $disk,
                    'storage_path' => $path,
                    'content' => $normalized->text,
                    'pages' => $normalized->pages,
                    'metadata' => $parsed->metadata ?: null,
                    'warnings' => $parsed->warnings ?: null,
                ]);

                $document->forceFill([
                    'status' => DocumentStatus::Pending,
                    'content_hash' => $normalized->hash,
                    'latest_version_id' => $version->id,
                    'error' => null,
                ])->save();

                return new IngestOutcome(IngestOutcome::CHANGED, $document, $version);
            });
        });
    }

    /**
     * @return string the real MIME type
     */
    protected function validate(RawContent $raw): string
    {
        $maxBytes = (int) config('starter.rag.limits.max_file_mb', 20) * 1024 * 1024;

        if (strlen($raw->bytes) > $maxBytes) {
            throw new UnreadableDocumentException((string) __('The file is larger than :max MB.', ['max' => (int) config('starter.rag.limits.max_file_mb', 20)]));
        }

        $mime = $this->parsers->detect($raw->bytes, $raw->name, $raw->mimeType);

        // The declared type must agree with the content (no disguised files)...
        if ($raw->mimeType === 'application/pdf' && $mime !== 'application/pdf') {
            throw new UnreadableDocumentException((string) __('The file is not a valid PDF.'));
        }

        return $mime;
    }

    /**
     * Keep the original on the private disk (uploads already are).
     *
     * @return array{0: string|null, 1: string|null}
     */
    protected function storeOriginal(Document $document, RawContent $raw, int $number, string $mime): array
    {
        if ($raw->storagePath !== null) {
            return [$raw->storageDisk, $raw->storagePath];
        }

        $disk = (string) config('starter.rag.storage_disk', 'local');
        $extension = match ($mime) {
            'application/pdf' => 'pdf',
            'text/html', 'application/xhtml+xml' => 'html',
            'text/markdown' => 'md',
            default => 'txt',
        };
        $path = "rag/{$document->collection_id}/{$document->source_id}/{$document->id}/v{$number}.{$extension}";

        Storage::disk($disk)->put($path, $raw->bytes);

        return [$disk, $path];
    }

    protected function fail(Document $document, string $error): IngestOutcome
    {
        $document->forceFill([
            // An indexed document keeps serving its current version...
            'status' => $document->current_version_id !== null ? $document->status : DocumentStatus::Failed,
            'error' => Str::limit($error, 500),
        ])->save();

        return new IngestOutcome(IngestOutcome::FAILED, $document);
    }

    protected function sanitize(Throwable $exception): string
    {
        return Str::limit(preg_replace('#(/[\w.-]+)+/#', '', $exception->getMessage()) ?? '', 300);
    }
}
