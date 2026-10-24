<?php

namespace Starter\Knowledge\Ingestion\Sources;

use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use RuntimeException;
use Starter\Knowledge\Models\Source;

/**
 * Files under a prefix of an allowed filesystem disk (for example an S3
 * compatible bucket configured in config/filesystems.php). Only disks
 * listed in starter.rag.disks can be used.
 */
class DiskDriver implements SourceDriver
{
    public function rules(): array
    {
        return [
            'config.disk' => ['required', 'string', Rule::in((array) config('starter.rag.disks', []))],
            'config.prefix' => ['nullable', 'string', 'max:255', 'not_regex:/\.\./'],
            'config.max_files' => ['nullable', 'integer', 'min:1', 'max:5000'],
        ];
    }

    public function discover(Source $source, SyncContext $context): iterable
    {
        $disk = (string) ($source->config['disk'] ?? '');

        if (! in_array($disk, (array) config('starter.rag.disks', []), true)) {
            throw new RuntimeException((string) __('The disk :disk is not allowed as a knowledge source.', ['disk' => $disk]));
        }

        $storage = Storage::disk($disk);
        $max = (int) ($source->config['max_files'] ?? 500);
        $count = 0;

        foreach ($storage->allFiles(trim((string) ($source->config['prefix'] ?? ''), '/')) as $path) {
            if (! in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), UploadDriver::EXTENSIONS, true)) {
                continue;
            }

            if (++$count > $max) {
                $context->truncated = true;

                break;
            }

            $context->checkpoint();

            yield new SourceItem(
                externalId: 'disk:'.$path,
                loader: fn () => new RawContent((string) $storage->get($path), null, basename($path)),
                metadata: ['path' => $path],
                marker: $storage->lastModified($path).':'.$storage->size($path),
            );
        }
    }
}
