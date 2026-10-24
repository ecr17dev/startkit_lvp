<?php

namespace App\Http\Controllers\Admin\Knowledge;

use App\Actions\Knowledge\ControlJob;
use App\Actions\Knowledge\DeleteKnowledge;
use App\Actions\Knowledge\SaveCollection;
use App\Http\Controllers\Controller;
use App\Support\KnowledgePresenter;
use App\Support\Paginated;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Starter\Knowledge\Exceptions\RagUnavailableException;
use Starter\Knowledge\Models\Collection;
use Starter\Knowledge\Models\Document;
use Starter\Knowledge\Models\EmbeddingProfile;
use Starter\Knowledge\Models\IndexVersion;
use Starter\Knowledge\Models\RagJob;
use Starter\Knowledge\Models\Source;
use Starter\Knowledge\Support\Access;

class CollectionController extends Controller
{
    public function store(Request $request, SaveCollection $action): RedirectResponse
    {
        try {
            $collection = $action->handle($this->actor($request), $request->all());
        } catch (RagUnavailableException $exception) {
            throw ValidationException::withMessages(['rag' => $exception->getMessage()]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Collection created.')]);

        return to_route('admin.knowledge.collections.show', $collection);
    }

    public function show(Request $request, Collection $collection): Response
    {
        $user = $this->actor($request);
        abort_unless(Access::canSeeCollection($user, $collection), 403);

        $canReadContent = Access::canReadContent($user, $collection);
        $filters = $request->validate([
            'status' => ['nullable', 'string', 'max:20'],
            'source' => ['nullable', 'integer'],
            'search' => ['nullable', 'string', 'max:120'],
        ]);

        $documents = $canReadContent
            ? Document::query()->with('source')->where('collection_id', $collection->id)
                ->when($filters['status'] ?? null, fn ($query, $status) => $status === 'deleted' ? $query->whereNotNull('deleted_at') : $query->alive()->where('status', $status), fn ($query) => $query->alive())
                ->when($filters['source'] ?? null, fn ($query, $source) => $query->where('source_id', $source))
                ->when($filters['search'] ?? null, fn ($query, $search) => $query->where(fn ($query) => $query->whereLike('title', '%'.$search.'%')->orWhereLike('external_id', '%'.$search.'%')))
                ->orderByDesc('updated_at')
                ->paginate(20)
                ->withQueryString()
            : null;

        return Inertia::render('admin/knowledge/collections/Show', [
            'collection' => KnowledgePresenter::collection($collection->load('profile', 'activeVersion.profile')),
            'sources' => $collection->sources()->orderBy('name')->get()->map(fn (Source $source) => KnowledgePresenter::source($source)),
            'documents' => $documents !== null ? [
                'data' => $documents->getCollection()->map(fn (Document $document) => KnowledgePresenter::document($document))->values(),
                'meta' => Paginated::meta($documents),
            ] : null,
            'filters' => $filters,
            'jobs' => KnowledgePresenter::jobs(RagJob::query()->where('collection_id', $collection->id)->whereNull('parent_id'), 25),
            'versions' => $collection->indexVersions()->with('profile')->orderByDesc('number')->get()->map(fn (IndexVersion $version) => KnowledgePresenter::indexVersion($version)),
            'profiles' => EmbeddingProfile::query()->where('status', EmbeddingProfile::READY)->orderBy('name')->get()->map(fn (EmbeddingProfile $profile) => KnowledgePresenter::profile($profile)),
            'options' => KnowledgePresenter::options(),
            'access' => [
                'readContent' => $canReadContent,
            ],
        ]);
    }

    public function update(Request $request, Collection $collection, SaveCollection $action): RedirectResponse
    {
        try {
            $action->handle($this->actor($request), $request->all(), $collection);
        } catch (RagUnavailableException $exception) {
            throw ValidationException::withMessages(['rag' => $exception->getMessage()]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Collection saved.')]);

        return back();
    }

    public function destroy(Request $request, Collection $collection, DeleteKnowledge $action): RedirectResponse
    {
        $action->collection($this->actor($request), $collection, (string) $request->input('confirmation'));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('The collection is being deleted.')]);

        return to_route('admin.knowledge.index');
    }

    public function reindex(Request $request, Collection $collection, ControlJob $action): RedirectResponse
    {
        $action->reindex($this->actor($request), $collection, $request->all());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Reindex started. The current version keeps answering until the new one is published.')]);

        return back();
    }
}
