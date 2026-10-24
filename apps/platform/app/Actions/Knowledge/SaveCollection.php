<?php

namespace App\Actions\Knowledge;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Starter\Knowledge\Enums\Capability;
use Starter\Knowledge\Enums\ConnectionKind;
use Starter\Knowledge\Enums\SearchMethod;
use Starter\Knowledge\Exceptions\RagUnavailableException;
use Starter\Knowledge\Knowledge;
use Starter\Knowledge\Models\Collection;
use Starter\Knowledge\Models\Document;
use Starter\Knowledge\Models\EmbeddingProfile;
use Starter\Knowledge\Models\RagConnection;
use Starter\Knowledge\Store\ExternalApi\RetrievalApiClient;
use Starter\Knowledge\Store\ExternalApi\RetrievalApiException;
use Starter\Knowledge\Store\VectorStore;

/**
 * Creates or updates a collection. A new profile or chunking applies to
 * the next index version (reindex); a new access list applies at once to
 * the stored chunks that inherit it.
 */
class SaveCollection
{
    public function __construct(protected Knowledge $knowledge, protected VectorStore $store, protected RetrievalApiClient $api) {}

    /**
     * @return array<string, mixed>
     */
    public static function rules(?Collection $collection = null, ?RagConnection $connection = null): array
    {
        $remote = $connection?->kind === ConnectionKind::Api;

        return [
            'name' => ['required', 'string', 'max:120'],
            'slug' => ['nullable', 'string', 'max:80', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', Rule::unique('rag_collections', 'slug')->ignore($collection?->id)],
            'description' => ['nullable', 'string', 'max:1000'],
            ...($remote ? [
                /** Collection id in the retrieval API (retrieval API connections only). */
                'external_id' => ['required', 'string', 'max:200', Rule::unique('rag_collections', 'external_id')->where('connection_id', $connection->id)->ignore($collection?->id)],
                'embedding_profile_id' => ['prohibited'],
            ] : [
                /** A ready profile of the collection connection. */
                'embedding_profile_id' => ['required', 'integer', Rule::exists('rag_embedding_profiles', 'id')->where('status', EmbeddingProfile::READY)->when($connection !== null, fn ($rule) => $rule->where('connection_id', $connection?->id))],
            ]),
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
        // New collections are created on the active connection (it must
        // accept ingestion, or serve searches for a retrieval API); an
        // existing one keeps its own...
        $connection = $collection === null ? $this->knowledge->ensureAvailable() : $this->knowledge->ensureFor($collection, Capability::Query);

        if ($collection === null && ! $connection->can($connection->kind === ConnectionKind::Api ? Capability::Query : Capability::Ingest)) {
            throw new RagUnavailableException((string) __('The knowledge connection does not support this operation.'));
        }

        $input['chunking'] = [...Collection::defaultChunking(), ...((array) ($input['chunking'] ?? []))];
        $input['search'] = [...Collection::defaultSearch(), ...((array) ($input['search'] ?? []))];
        $input['acl'] ??= ['everyone'];

        /** @var array<string, mixed> $data */
        $data = Validator::make($input, self::rules($collection, $connection))->validate();

        if ($connection->kind === ConnectionKind::Api) {
            $this->checkRemote($connection, $data);
        }

        $data['search']['top_k'] = (int) $data['search']['top_k'];
        $data['search']['max_context_tokens'] = (int) $data['search']['max_context_tokens'];
        $data['search']['min_score'] = isset($data['search']['min_score']) ? (float) $data['search']['min_score'] : null;
        $data['search']['min_text_rank'] = isset($data['search']['min_text_rank']) ? (float) $data['search']['min_text_rank'] : null;
        $data['chunking'] = array_map('intval', $data['chunking']);
        $data['acl'] = array_values($data['acl']);

        if ($collection === null) {
            $collection = Collection::query()->create([
                ...$data,
                'embedding_profile_id' => $data['embedding_profile_id'] ?? null,
                'slug' => $data['slug'] ?? $this->slug((string) $data['name']),
                'connection_id' => $connection->id,
                'created_by' => $actor->id,
            ]);

            audit('rag.collections.created', $collection, ['name' => $collection->name], $actor);

            return $collection;
        }

        $aclChanged = $collection->acl !== $data['acl'];

        DB::transaction(fn () => $collection->fill([...$data, 'slug' => $data['slug'] ?? $collection->slug])->save());

        // Remote collections keep no chunks here: the access list is sent
        // with every search...
        if ($aclChanged && ! $collection->isRemote()) {
            $this->propagateAcl($collection);
        }

        audit('rag.collections.updated', $collection, ['name' => $collection->name, 'acl_changed' => $aclChanged], $actor);

        return $collection;
    }

    /**
     * A retrieval API without guaranteed isolation only serves public
     * collections, and a listed collection must exist on the service.
     *
     * @param  array<string, mixed>  $data
     */
    protected function checkRemote(RagConnection $connection, array $data): void
    {
        if (! $connection->setting('enforces_acl', false) && array_values($data['acl']) !== ['everyone']) {
            throw ValidationException::withMessages(['acl' => __('This retrieval API does not guarantee isolation: only public collections (every authenticated user) are allowed.')]);
        }

        if (! $connection->can(Capability::ListCollections)) {
            return;
        }

        try {
            $listed = array_column($this->api->collections($connection), 'id');
        } catch (RetrievalApiException $exception) {
            throw ValidationException::withMessages(['external_id' => $exception->getMessage()]);
        }

        if (! in_array((string) $data['external_id'], $listed, true)) {
            throw ValidationException::withMessages(['external_id' => __('The retrieval API does not list this collection.')]);
        }
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
