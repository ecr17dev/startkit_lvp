<?php

namespace App\Http\Controllers\Api\V1\Rag;

use App\Actions\Knowledge\SaveSource;
use App\Actions\Knowledge\SyncSource;
use App\Http\Controllers\Controller;
use App\Support\KnowledgePresenter;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Starter\Knowledge\Exceptions\RagUnavailableException;
use Starter\Knowledge\Models\Collection;
use Starter\Knowledge\Models\Source;
use Starter\Knowledge\Support\Access;

#[Group('Knowledge (RAG)', weight: 60)]
class CollectionController extends Controller
{
    /**
     * Knowledge status: mode, connection, capabilities and diagnostics.
     */
    public function status(): JsonResponse
    {
        Gate::authorize('rag.collections.read');

        return response()->json(['data' => KnowledgePresenter::status()]);
    }

    /**
     * Collections you can see.
     */
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('rag.collections.read');
        $user = $this->actor($request);

        return response()->json([
            'data' => Collection::query()->with(['profile', 'activeVersion.profile'])->orderBy('name')->get()
                ->filter(fn (Collection $collection) => Access::canSeeCollection($user, $collection))
                ->map(fn (Collection $collection) => KnowledgePresenter::collection($collection))
                ->values(),
        ]);
    }

    /**
     * A collection with its sources and index versions.
     */
    public function show(Request $request, Collection $collection): JsonResponse
    {
        abort_unless(Access::canSeeCollection($this->actor($request), $collection), 403);

        return response()->json(['data' => [
            ...KnowledgePresenter::collection($collection->load('profile', 'activeVersion.profile')),
            'sources' => $collection->sources()->orderBy('name')->get()->map(fn (Source $source) => KnowledgePresenter::source($source)),
            'versions' => $collection->indexVersions()->with('profile')->orderByDesc('number')->get()->map(fn ($version) => KnowledgePresenter::indexVersion($version)),
        ]]);
    }

    /**
     * Create a source and start its sync.
     *
     * Types url, sitemap, adapter and disk take a JSON `config`; files are
     * uploaded with multipart/form-data (`files[]`). Responds 202 with the
     * sync `job_id`.
     */
    public function storeSource(Request $request, Collection $collection, SaveSource $action): JsonResponse
    {
        try {
            ['source' => $source, 'job' => $job] = $action->handle($this->actor($request), $collection, $request->all());
        } catch (RagUnavailableException $exception) {
            return response()->json(['message' => $exception->getMessage()], 503);
        }

        return response()->json(['data' => KnowledgePresenter::source($source), 'job_id' => $job?->id], 202);
    }

    /**
     * Start a sync of a source (an open sync is returned instead of a new one).
     */
    public function sync(Request $request, Source $source, SyncSource $action): JsonResponse
    {
        try {
            $job = $action->handle($this->actor($request), $source, ['force' => $request->boolean('force')]);
        } catch (RagUnavailableException $exception) {
            return response()->json(['message' => $exception->getMessage()], 503);
        }

        return response()->json(['job_id' => $job->id, 'data' => KnowledgePresenter::job($job)], 202);
    }
}
