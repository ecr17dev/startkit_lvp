<?php

namespace App\Actions\Knowledge;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Starter\Knowledge\Enums\SourceType;
use Starter\Knowledge\Ingestion\Parsers\ParserRegistry;
use Starter\Knowledge\Models\RagJob;
use Starter\Knowledge\Models\Source;

/**
 * Stores TXT, Markdown and PDF files of an upload source on the private
 * RAG disk (validated by content, not only by extension) and syncs it.
 */
class AddSourceFiles
{
    public const EXTENSIONS = ['txt', 'md', 'markdown', 'pdf'];

    public const MIME_TYPES = ['text/plain', 'text/markdown', 'application/pdf'];

    public function __construct(protected ParserRegistry $parsers, protected SyncSource $sync) {}

    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        $maxFiles = (int) config('starter.rag.limits.max_files', 20);

        return [
            'files' => ['required', 'array', 'min:1', 'max:'.$maxFiles],
            'files.*' => ['required', 'file', 'max:'.((int) config('starter.rag.limits.max_file_mb', 20) * 1024), 'extensions:'.implode(',', self::EXTENSIONS)],
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function handle(User $actor, Source $source, array $input): ?RagJob
    {
        Gate::forUser($actor)->authorize('rag.sources.manage');

        if ($source->type !== SourceType::Upload) {
            throw ValidationException::withMessages(['files' => __('Only file sources accept uploads.')]);
        }

        Validator::make($input, self::rules())->validate();

        /** @var list<UploadedFile> $files */
        $files = $input['files'];
        $this->store($source, $files);

        audit('rag.sources.files_added', $source, ['files' => count($files)], $actor);

        return $this->sync->handle($actor, $source);
    }

    /**
     * @param  list<UploadedFile>  $files
     */
    public function store(Source $source, array $files): void
    {
        $disk = (string) config('starter.rag.storage_disk', 'local');
        $entries = [];

        foreach ($files as $index => $file) {
            $content = (string) file_get_contents($file->getRealPath());
            $mime = $this->parsers->detect($content, $file->getClientOriginalName(), $file->getClientMimeType());

            $extension = strtolower($file->getClientOriginalExtension()) ?: 'txt';

            // The real content must match the extension (no disguised files)...
            if (! in_array($mime, self::MIME_TYPES, true) || ($extension === 'pdf') !== ($mime === 'application/pdf')) {
                throw ValidationException::withMessages(["files.{$index}" => __('The file :name is not a TXT, Markdown or PDF document.', ['name' => $file->getClientOriginalName()])]);
            }

            $name = Str::limit(basename($file->getClientOriginalName()), 200, '');
            $path = $file->storeAs("rag/{$source->collection_id}/{$source->id}/files", Str::uuid().'.'.$extension, $disk);

            $entries[mb_strtolower($name)] = [
                'name' => $name,
                'path' => $path,
                'disk' => $disk,
                'mime' => $mime,
                'size' => $file->getSize(),
                'sha256' => hash('sha256', $content),
                'uploaded_at' => now()->toIso8601String(),
            ];
        }

        DB::transaction(function () use ($source, $entries) {
            $locked = Source::query()->lockForUpdate()->findOrFail($source->id);
            $current = collect((array) ($locked->config['files'] ?? []))->keyBy(fn (array $file) => mb_strtolower((string) $file['name']))->all();

            // Same name = new version of the same document...
            $locked->forceFill(['config' => [...$locked->config, 'files' => array_values([...$current, ...$entries])]])->save();
            $source->setRawAttributes($locked->getAttributes(), true);
        });
    }
}
