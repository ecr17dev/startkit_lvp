<?php

namespace App\Actions\Knowledge;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Starter\Knowledge\Enums\Capability;
use Starter\Knowledge\Enums\SourceStatus;
use Starter\Knowledge\Enums\SourceType;
use Starter\Knowledge\Ingestion\Sources\SourceDrivers;
use Starter\Knowledge\Knowledge;
use Starter\Knowledge\Models\Collection;
use Starter\Knowledge\Models\Document;
use Starter\Knowledge\Models\RagJob;
use Starter\Knowledge\Models\Source;
use Starter\Knowledge\Store\VectorStore;

/**
 * Creates (and syncs) or updates a source of a collection.
 */
class SaveSource
{
    public function __construct(
        protected Knowledge $knowledge,
        protected SourceDrivers $drivers,
        protected AddSourceFiles $files,
        protected SyncSource $sync,
        protected VectorStore $store,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function rules(?SourceType $type, bool $creating): array
    {
        return [
            'type' => [$creating ? 'required' : 'prohibited', Rule::enum(SourceType::class)],
            'name' => ['required', 'string', 'max:160'],
            'config' => ['nullable', 'array'],
            ...AccessList::rules('acl', false),
            /** Automatic sync: hourly, daily or weekly (empty = manual). */
            'schedule' => ['nullable', Rule::in(Source::SCHEDULES)],
            ...($type !== null ? $this->drivers->for($type)->rules() : []),
            ...($creating && $type === SourceType::Upload ? AddSourceFiles::rules() : []),
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{source: Source, job: RagJob|null}
     */
    public function handle(User $actor, Collection $collection, array $input, ?Source $source = null): array
    {
        Gate::forUser($actor)->authorize('rag.sources.manage');
        $this->knowledge->ensureAvailable(Capability::Ingest);

        $type = $source->type ?? SourceType::tryFrom((string) ($input['type'] ?? ''));

        /** @var array<string, mixed> $data */
        $data = Validator::make($input, $this->rules($type, $source === null))->validate();
        $config = $this->config($type, (array) ($data['config'] ?? []), $source);
        $acl = array_values((array) ($data['acl'] ?? [])) ?: null;
        $schedule = $data['schedule'] ?? null;

        if ($source === null) {
            /** @var SourceType $type */
            $source = $collection->sources()->create([
                'type' => $type,
                'name' => $data['name'],
                'config' => $config,
                'acl' => $acl,
                'schedule' => $schedule,
                'next_sync_at' => $schedule !== null ? now()->addMinute() : null,
                'status' => SourceStatus::Active,
                'created_by' => $actor->id,
            ]);

            if ($type === SourceType::Upload) {
                /** @var list<UploadedFile> $uploads */
                $uploads = $input['files'] ?? [];
                $this->files->store($source, $uploads);
            }

            audit('rag.sources.created', $source, ['name' => $source->name, 'type' => $source->type->value, 'collection' => $collection->slug], $actor);

            return ['source' => $source, 'job' => $this->sync->handle($actor, $source)];
        }

        $aclChanged = $source->acl !== $acl;

        $source->fill([
            'name' => $data['name'],
            'config' => $type === SourceType::Upload ? $source->config : $config,
            'acl' => $acl,
            'schedule' => $schedule,
            'next_sync_at' => $schedule === null ? null : ($source->schedule === $schedule ? $source->next_sync_at : $source->nextRunAfter(now())),
        ])->save();

        if ($aclChanged) {
            $ids = Document::query()->where('source_id', $source->id)->where(fn ($query) => $query->whereNull('acl')->orWhereJsonLength('acl', 0))->pluck('id')->map(fn ($id) => (int) $id)->all();
            $this->store->updateAclMany($collection->connection, $ids, $source->effectiveAcl());
        }

        audit('rag.sources.updated', $source, ['name' => $source->name, 'acl_changed' => $aclChanged], $actor);

        return ['source' => $source, 'job' => null];
    }

    /**
     * Keep only the keys the driver understands.
     *
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    protected function config(?SourceType $type, array $config, ?Source $source): array
    {
        return match ($type) {
            SourceType::Url => [
                'urls' => array_values(array_unique(array_map('trim', (array) ($config['urls'] ?? [])))),
                'depth' => (int) ($config['depth'] ?? 0),
                'max_pages' => (int) ($config['max_pages'] ?? 50),
                'path_prefix' => $config['path_prefix'] ?? null,
            ],
            SourceType::Sitemap => ['url' => trim((string) ($config['url'] ?? '')), 'max_pages' => (int) ($config['max_pages'] ?? 100), 'path_prefix' => $config['path_prefix'] ?? null],
            SourceType::Adapter => ['adapter' => (string) ($config['adapter'] ?? '')],
            SourceType::Disk => ['disk' => (string) ($config['disk'] ?? ''), 'prefix' => $config['prefix'] ?? null, 'max_files' => (int) ($config['max_files'] ?? 500)],
            SourceType::Upload => $source->config ?? ['files' => []],
            null => [],
        };
    }
}
