<?php

namespace App\Http\Controllers\Api\V1\Rag;

use App\Actions\Knowledge\DeleteKnowledge;
use App\Http\Controllers\Controller;
use App\Support\KnowledgePresenter;
use App\Support\Paginated;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Starter\Knowledge\Models\Collection;
use Starter\Knowledge\Models\Document;
use Starter\Knowledge\Store\VectorStore;
use Starter\Knowledge\Support\Access;

#[Group('Knowledge (RAG)', weight: 60)]
class DocumentController extends Controller
{
    /**
     * Documents of a collection.
     */
    public function index(Request $request, Collection $collection): JsonResponse
    {
        abort_unless(Access::canReadContent($this->actor($request), $collection), 403);

        $data = $request->validate([
            /** pending, indexing, indexed, failed, empty, duplicate or deleted. */
            'status' => ['nullable', 'string', 'max:20'],
            'source_id' => ['nullable', 'integer'],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
        ]);

        $documents = Document::query()->with('source')->where('collection_id', $collection->id)
            ->when($data['status'] ?? null, fn ($query, $status) => $status === 'deleted' ? $query->whereNotNull('deleted_at') : $query->alive()->where('status', $status), fn ($query) => $query->alive())
            ->when($data['source_id'] ?? null, fn ($query, $source) => $query->where('source_id', $source))
            ->orderByDesc('updated_at')
            ->paginate((int) ($data['per_page'] ?? 25));

        return response()->json([
            'data' => $documents->getCollection()->map(fn (Document $document) => KnowledgePresenter::document($document))->values(),
            'meta' => Paginated::meta($documents),
        ]);
    }

    /**
     * A document with its chunks in the active index version.
     */
    public function show(Request $request, Document $document, VectorStore $store): JsonResponse
    {
        $document->load('collection.activeVersion', 'source');
        abort_unless(Access::canReadDocument($this->actor($request), $document), 403);

        $active = $document->collection->activeVersion;

        return response()->json(['data' => [
            ...KnowledgePresenter::document($document),
            'metadata' => $document->metadata,
            'chunks' => $active !== null && ! $document->isDeleted()
                ? array_map(fn (object $chunk) => [
                    'id' => (int) $chunk->id,
                    'ordinal' => (int) $chunk->ordinal,
                    'content' => $chunk->content,
                    'token_count' => (int) $chunk->token_count,
                    'metadata' => json_decode((string) $chunk->metadata, true),
                ], $store->documentChunks($document->collection->connection, $active->id, $document->id))
                : [],
        ]]);
    }

    /**
     * Delete a document: it leaves the searches at once and a job purges it.
     */
    public function destroy(Request $request, Document $document, DeleteKnowledge $action): JsonResponse
    {
        $job = $action->document($this->actor($request), $document);

        return response()->json(['job_id' => $job->id], 202);
    }
}
