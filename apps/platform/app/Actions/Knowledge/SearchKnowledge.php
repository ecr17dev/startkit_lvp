<?php

namespace App\Actions\Knowledge;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Starter\Knowledge\Enums\Capability;
use Starter\Knowledge\Enums\SearchMethod;
use Starter\Knowledge\Knowledge;
use Starter\Knowledge\Models\Collection;
use Starter\Knowledge\Retrieval\RetrievalQuery;
use Starter\Knowledge\Retrieval\RetrievalResult;
use Starter\Knowledge\Retrieval\Retriever;

/**
 * Search the authorized knowledge (lab and API). The collections are
 * limited to those whose access list includes the actor; the retriever
 * applies the document access lists inside the query.
 */
class SearchKnowledge
{
    public function __construct(protected Knowledge $knowledge, protected Retriever $retriever) {}

    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'query' => ['required', 'string', 'max:2000'],
            /** Collections to search (default: every collection you can access). */
            'collection_ids' => ['nullable', 'array', 'max:20'],
            'collection_ids.*' => ['integer', 'distinct'],
            /** vector, text or hybrid (default: the collection setting). */
            'method' => ['nullable', Rule::enum(SearchMethod::class)],
            'top_k' => ['nullable', 'integer', 'between:1,50'],
            'min_score' => ['nullable', 'numeric', 'between:0,1'],
            'min_text_rank' => ['nullable', 'numeric', 'between:0,1'],
            'max_context_tokens' => ['nullable', 'integer', 'between:100,32000'],
            'source_ids' => ['nullable', 'array', 'max:50'],
            'source_ids.*' => ['integer'],
            /** Force exact (true) or approximate (false) vector search. */
            'exact' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function handle(User $actor, array $input, string $surface = 'api'): RetrievalResult
    {
        Gate::forUser($actor)->authorize('rag.playground.use');
        $this->knowledge->ensureAvailable(Capability::Query);

        /** @var array<string, mixed> $data */
        $data = Validator::make($input, self::rules())->validate();

        return $this->retriever->search($this->query($actor, $data, $surface));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function query(User $actor, array $data, string $surface): RetrievalQuery
    {
        $collections = array_values(Collection::query()
            ->where('status', Collection::ACTIVE)
            ->when($data['collection_ids'] ?? null, fn ($query, array $ids) => $query->whereIn('id', $ids))
            ->orderBy('name')
            ->get()
            ->filter(fn (Collection $collection) => $collection->isVisibleTo($actor))
            ->all());

        $query = RetrievalQuery::for((string) $data['query'], $actor, $collections, [
            'method' => $data['method'] ?? null,
            'top_k' => $data['top_k'] ?? null,
            'min_score' => $data['min_score'] ?? null,
            'min_text_rank' => $data['min_text_rank'] ?? null,
            'max_context_tokens' => $data['max_context_tokens'] ?? null,
        ], $surface);

        $query->sourceIds = array_values(array_map('intval', (array) ($data['source_ids'] ?? [])));
        $query->exact = isset($data['exact']) ? (bool) $data['exact'] : null;

        return $query;
    }
}
