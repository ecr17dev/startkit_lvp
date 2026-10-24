<?php

namespace App\Actions\Knowledge;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Starter\Knowledge\Enums\SearchMethod;
use Starter\Knowledge\Knowledge;
use Starter\Knowledge\Models\Collection;
use Starter\Knowledge\Models\Document;
use Starter\Knowledge\Models\EmbeddingProfile;
use Starter\Knowledge\Store\VectorStore;

/**
 * Creates or updates a collection. A new profile or chunking applies to
 * the next index version (reindex); a new access list applies at once to
 * the stored chunks that inherit it.
 */
class SaveCollection
{
    public function __construct(protected Knowledge $knowledge, protected VectorStore $store) {}

    /**
     * @return array<string, mixed>
     */
    public static function rules(?Collection $collection = null): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'slug' => ['nullable', 'string', 'max:80', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', Rule::unique('rag_collections', 'slug')->ignore($collection?->id)],
            'description' => ['nullable', 'string', 'max:1000'],
            'embedding_profile_id' => ['required', 'integer', Rule::exists('rag_embedding_profiles', 'id')->where('status', EmbeddingProfile::READY)],
            /** Language of the full text search: simple, es, en, pt, fr, de or it. */
            'language' => ['required', Rule::in(array_keys(Collection::LANGUAGES))],
            'chunking' => ['required', 'array'],
            'chunking.target_tokens' => ['required', 'integer', 'between:100,2000'],
            'chunking.overlap_tokens' => ['required', 'integer', 'min:0', 'lte:chunking.target_tokens'],
            'chunking.max_tokens' => ['required', 'integer', 'gte:chunking.target_tokens', 'max:4000'],
            'search' => ['required', 'array'],
            'search.method' => ['required', Rule::enum(SearchMethod::class)],
            'search.top_k' => ['required', 'integer', 'between:1,50'],
            /** Minimum vector similarity (0-1, empty = none). */
            'search.min_score' => ['nullable', 'numeric', 'between:0,1'],
            'search.min_text_rank' => ['nullable', 'numeric', 'between:0,1'],
            'search.max_context_tokens' => ['required', 'integer', 'between:200,32000'],
            ...AccessList::rules('acl'),
            'retention_days' => ['nullable', 'integer', 'between:1,3650'],
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function handle(User $actor, array $input, ?Collection $collection = null): Collection
    {
        Gate::forUser($actor)->authorize('rag.collections.manage');
        $connection = $this->knowledge->ensureAvailable();

        $input['chunking'] = [...Collection::defaultChunking(), ...((array) ($input['chunking'] ?? []))];
        $input['search'] = [...Collection::defaultSearch(), ...((array) ($input['search'] ?? []))];
        $input['acl'] ??= ['everyone'];

        /** @var array<string, mixed> $data */
        $data = Validator::make($input, self::rules($collection))->validate();

        $data['search']['top_k'] = (int) $data['search']['top_k'];
        $data['search']['max_context_tokens'] = (int) $data['search']['max_context_tokens'];
        $data['search']['min_score'] = isset($data['search']['min_score']) ? (float) $data['search']['min_score'] : null;
        $data['search']['min_text_rank'] = isset($data['search']['min_text_rank']) ? (float) $data['search']['min_text_rank'] : null;
        $data['chunking'] = array_map('intval', $data['chunking']);
        $data['acl'] = array_values($data['acl']);

        if ($collection === null) {
            $collection = Collection::query()->create([
                ...$data,
                'slug' => $data['slug'] ?? $this->slug((string) $data['name']),
                'connection_id' => $connection->id,
                'created_by' => $actor->id,
            ]);

            audit('rag.collections.created', $collection, ['name' => $collection->name], $actor);

            return $collection;
        }

        $aclChanged = $collection->acl !== $data['acl'];

        DB::transaction(fn () => $collection->fill([...$data, 'slug' => $data['slug'] ?? $collection->slug])->save());

        if ($aclChanged) {
            $this->propagateAcl($collection);
        }

        audit('rag.collections.updated', $collection, ['name' => $collection->name, 'acl_changed' => $aclChanged], $actor);

        return $collection;
    }

    /**
     * Chunks of documents inheriting the collection access list.
     */
    protected function propagateAcl(Collection $collection): void
    {
        $sourceIds = $collection->sources()->where(fn ($query) => $query->whereNull('acl')->orWhereJsonLength('acl', 0))->pluck('id');
        $documentIds = Document::query()->whereIn('source_id', $sourceIds)->where(fn ($query) => $query->whereNull('acl')->orWhereJsonLength('acl', 0))->pluck('id')->map(fn ($id) => (int) $id)->all();

        $this->store->updateAclMany($collection->connection, $documentIds, $collection->acl);
    }

    protected function slug(string $name): string
    {
        $base = Str::slug($name) ?: 'collection';
        $slug = $base;
        $suffix = 2;

        while (Collection::query()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$suffix++;
        }

        return $slug;
    }
}
