<?php

namespace App\Http\Controllers\Api\Retrieval;

use App\Actions\Knowledge\SearchKnowledge;
use App\Http\Controllers\Controller;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Starter\Knowledge\Models\Collection;
use Starter\Knowledge\Models\Document;
use Starter\Knowledge\Retrieval\Hit;
use Starter\Knowledge\Store\ExternalApi\RetrievalApiClient;
use Starter\Knowledge\Support\Access;

/**
 * Reference implementation of the retrieval API contract (version 1), so
 * another installation can use this one as its external knowledge. The
 * token user caps what is visible; the principals sent for the end user
 * only narrow it further (a chunk must match both access lists).
 */
#[Group('Retrieval API (contract v1)', weight: 65)]
class RetrievalServerController extends Controller
{
    /**
     * Contract version and capabilities.
     */
    public function capabilities(): JsonResponse
    {
        Gate::authorize('rag.playground.use');

        return response()->json([
            'version' => RetrievalApiClient::VERSION,
            'capabilities' => RetrievalApiClient::SUPPORTED,
            'enforces_acl' => true,
        ]);
    }

    /**
     * Collections of this installation the token user can search (remote
     * collections are never re-exported).
     */
    public function collections(Request $request): JsonResponse
    {
        Gate::authorize('rag.playground.use');
        $user = $this->actor($request);

        return response()->json(['data' => Collection::query()->with('connection')->where('status', Collection::ACTIVE)->orderBy('name')->get()
            ->filter(fn (Collection $collection) => ! $collection->isRemote() && Access::canSeeCollection($user, $collection))
            ->map(fn (Collection $collection) => [
                'id' => $collection->slug,
                'name' => $collection->name,
                'description' => $collection->description,
                'documents' => $collection->documents()->alive()->where('status', 'indexed')->count(),
            ])->values()]);
    }

    /**
     * Indexed documents of a collection the token user can read (50 per page).
     */
    public function documents(Request $request, string $collection): JsonResponse
    {
        Gate::authorize('rag.playground.use');
        $user = $this->actor($request);
        $model = Collection::query()->with('connection')->where('slug', $collection)->first();

        abort_if($model === null || $model->isRemote() || ! Access::canReadContent($user, $model), 404);

        $page = Document::query()->with('collection', 'source')->where('collection_id', $model->id)->alive()->where('status', 'indexed')->orderBy('id')->paginate(50);

        return response()->json([
            'data' => $page->getCollection()->filter(fn (Document $document) => Access::canReadDocument($user, $document))->map(fn (Document $document) => [
                'id' => (string) $document->id,
                'title' => $document->title ?? $document->external_id,
                'uri' => $document->uri,
                'updated_at' => $document->indexed_at?->toIso8601String(),
            ])->values(),
            'next_page' => $page->hasMorePages() ? $page->currentPage() + 1 : null,
        ]);
    }

    /**
     * Search with the same rules as `POST /api/v1/rag/search`: the access
     * lists of the token user apply inside the query, and `principals`
     * (the end user of the calling installation) must also match.
     */
    public function search(Request $request, SearchKnowledge $action): JsonResponse
    {
        /** @var array{query: string, collections?: list<string>|null, top_k?: int|null, method?: string|null, min_score?: float|null, principals?: list<string>|null} $data */
        $data = Validator::make($request->all(), [
            'query' => ['required', 'string', 'max:2000'],
            /** Collection ids (slugs) to search; empty = every collection the token user can search. */
            'collections' => ['nullable', 'array', 'max:20'],
            'collections.*' => ['string', 'max:200'],
            'top_k' => ['nullable', 'integer', 'between:1,50'],
            'method' => ['nullable', 'string'],
            'min_score' => ['nullable', 'numeric', 'between:0,1'],
            /** Principals of the end user (everyone, user:{id}, role:{name}, permission:{name}). */
            'principals' => ['nullable', 'array', 'max:200'],
            'principals.*' => ['string', 'max:200'],
        ])->validate();

        $ids = isset($data['collections']) && $data['collections'] !== []
            ? (Collection::query()->whereIn('slug', $data['collections'])->pluck('id')->all() ?: [0])
            : null;

        $result = $action->handle($this->actor($request), array_filter([
            'query' => $data['query'],
            'collection_ids' => $ids,
            'top_k' => $data['top_k'] ?? null,
            'method' => in_array($data['method'] ?? null, ['vector', 'text', 'hybrid'], true) ? $data['method'] : null,
            'min_score' => $data['min_score'] ?? null,
            'principals' => $data['principals'] ?? null,
        ], fn ($value) => $value !== null), 'retrieval_api', localOnly: true);

        $slugs = Collection::query()->whereIn('id', array_unique(array_map(fn (Hit $hit) => $hit->collectionId, $result->hits)))->pluck('slug', 'id');

        return response()->json([
            'data' => ['hits' => array_map(fn (Hit $hit) => [
                'id' => (string) $hit->chunkId,
                'document_id' => (string) $hit->documentId,
                'collection' => $slugs[$hit->collectionId] ?? null,
                'title' => $hit->title(),
                'text' => $hit->content,
                'uri' => $hit->metadata['uri'] ?? null,
                'location' => $hit->location(),
                'score' => round($hit->score, 6),
                'score_type' => $hit->scoreType,
                'token_count' => $hit->tokenCount,
            ], $result->hits)],
            'usage' => ['cost' => $result->costKnown ? $result->cost : null, 'latency_ms' => $result->latencyMs],
        ]);
    }
}
