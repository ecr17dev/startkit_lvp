<?php

namespace App\Http\Controllers\Admin\Knowledge;

use App\Actions\Knowledge\DeleteKnowledge;
use App\Http\Controllers\Controller;
use App\Support\KnowledgePresenter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Starter\Knowledge\Models\Document;
use Starter\Knowledge\Models\DocumentVersion;
use Starter\Knowledge\Models\RagJob;
use Starter\Knowledge\Store\VectorStore;
use Starter\Knowledge\Support\Access;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DocumentController extends Controller
{
    public function show(Request $request, Document $document, VectorStore $store): Response
    {
        $document->load('source', 'collection.activeVersion', 'currentVersion', 'latestVersion');
        abort_unless(Access::canReadDocument($this->actor($request), $document), 403);

        $collection = $document->collection;
        $active = $collection->activeVersion;
        $chunks = $active !== null && ! $document->isDeleted()
            ? array_map(fn (object $chunk) => [
                'id' => (int) $chunk->id,
                'ordinal' => (int) $chunk->ordinal,
                'content' => $chunk->content,
                'token_count' => (int) $chunk->token_count,
                'metadata' => json_decode((string) $chunk->metadata, true),
            ], $store->documentChunks($collection->connection, $active->id, $document->id, 300))
            : [];

        $version = $document->latestVersion ?? $document->currentVersion;

        return Inertia::render('admin/knowledge/documents/Show', [
            'document' => [
                ...KnowledgePresenter::document($document),
                'metadata' => $document->metadata,
                'effective_acl' => $document->effectiveAcl(),
                'etag' => $document->etag,
                'last_modified' => $document->last_modified,
                'collection' => ['id' => $collection->id, 'name' => $collection->name, 'slug' => $collection->slug],
            ],
            'version' => $version !== null ? [
                'id' => $version->id,
                'number' => $version->number,
                'parser' => $version->parser,
                'content_hash' => $version->content_hash,
                'size' => $version->size,
                'pages' => $version->pages,
                'warnings' => $version->warnings,
                'has_original' => $version->storage_path !== null,
                'content' => mb_substr($version->content, 0, 20000),
                'truncated' => mb_strlen($version->content) > 20000,
                'is_current' => $version->id === $document->current_version_id,
            ] : null,
            'versions' => $document->versions()->orderByDesc('number')->get(['id', 'number', 'content_hash', 'parser', 'size', 'created_at'])->map(fn (DocumentVersion $item) => [
                'id' => $item->id,
                'number' => $item->number,
                'content_hash' => $item->content_hash,
                'parser' => $item->parser,
                'size' => $item->size,
                'created_at' => $item->created_at?->toIso8601String(),
            ]),
            'chunks' => $chunks,
            'profile' => $active !== null ? ['name' => $active->profile->name, 'model' => $active->profile->model, 'dimensions' => $active->profile->dimensions, 'version' => $active->number] : null,
            'jobs' => KnowledgePresenter::jobs(RagJob::query()->where('document_id', $document->id), 15),
        ]);
    }

    public function original(Request $request, Document $document): StreamedResponse
    {
        $document->load('collection', 'source');
        abort_unless(Access::canReadDocument($this->actor($request), $document), 403);

        $version = $document->latestVersion ?? $document->currentVersion;
        abort_if($version === null || $version->storage_path === null, 404);

        $disk = Storage::disk($version->storage_disk ?? (string) config('starter.rag.storage_disk', 'local'));
        abort_unless($disk->exists($version->storage_path), 404);

        audit('rag.documents.downloaded', $document, ['version' => $version->number], $this->actor($request));

        $extension = pathinfo($version->storage_path, PATHINFO_EXTENSION);

        return $disk->download($version->storage_path, str($document->title ?? 'document')->slug().'.'.$extension, [
            'Content-Type' => $version->mime_type ?? 'application/octet-stream',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function destroy(Request $request, Document $document, DeleteKnowledge $action): RedirectResponse
    {
        $action->document($this->actor($request), $document);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Document deleted. It no longer appears in searches.')]);

        return back();
    }
}
