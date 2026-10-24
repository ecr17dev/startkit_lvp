<?php

namespace Starter\Knowledge\Ingestion\Sources;

use Illuminate\Support\Facades\Storage;
use Starter\Knowledge\Models\Source;

/**
 * Uploaded files kept on the private RAG disk. A file uploaded again with
 * the same name becomes a new version of the same document.
 */
class UploadDriver implements SourceDriver
{
    public const EXTENSIONS = ['txt', 'md', 'markdown', 'pdf'];

    public function rules(): array
    {
        return [];
    }

    public function discover(Source $source, SyncContext $context): iterable
    {
        foreach ((array) ($source->config['files'] ?? []) as $file) {
            $disk = (string) ($file['disk'] ?? config('starter.rag.storage_disk', 'local'));
            $path = (string) $file['path'];
            $name = (string) $file['name'];

            yield new SourceItem(
                externalId: 'file:'.mb_strtolower($name),
                loader: fn () => Storage::disk($disk)->exists($path)
                    ? new RawContent((string) Storage::disk($disk)->get($path), $file['mime'] ?? null, $name, storageDisk: $disk, storagePath: $path)
                    : RawContent::failed((string) __('The uploaded file no longer exists.'), true),
                metadata: ['filename' => $name, 'uploaded_at' => $file['uploaded_at'] ?? null],
                marker: (string) ($file['sha256'] ?? ''),
            );
        }
    }
}
