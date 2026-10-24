<?php

namespace App\Http\Controllers\Api\V1\Rag;

use App\Actions\Knowledge\ControlJob;
use App\Http\Controllers\Controller;
use App\Support\KnowledgePresenter;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Starter\Knowledge\Models\RagJob;

#[Group('Knowledge (RAG)', weight: 60)]
class JobController extends Controller
{
    /**
     * Indexing jobs (latest first).
     */
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('rag.jobs.manage');

        $data = $request->validate([
            'collection_id' => ['nullable', 'integer'],
            /** queued, running, retrying, paused, succeeded, failed or cancelled. */
            'status' => ['nullable', 'string', 'max:16'],
            'limit' => ['nullable', 'integer', 'between:1,100'],
        ]);

        $query = RagJob::query()->whereNull('parent_id')
            ->when($data['collection_id'] ?? null, fn ($query, $id) => $query->where('collection_id', $id))
            ->when($data['status'] ?? null, fn ($query, $status) => $query->where('status', $status));

        return response()->json(['data' => KnowledgePresenter::jobs($query, (int) ($data['limit'] ?? 30))]);
    }

    /**
     * Progress of a job: stage, known progress, units, timings and cost.
     */
    public function show(RagJob $job): JsonResponse
    {
        Gate::authorize('rag.jobs.manage');

        return response()->json(['data' => KnowledgePresenter::job($job)]);
    }

    /**
     * Request the cooperative cancellation of a job.
     */
    public function cancel(Request $request, RagJob $job, ControlJob $action): JsonResponse
    {
        return response()->json(['data' => KnowledgePresenter::job($action->cancel($this->actor($request), $job))]);
    }

    /**
     * Retry a failed or cancelled job.
     */
    public function retry(Request $request, RagJob $job, ControlJob $action): JsonResponse
    {
        return response()->json(['data' => KnowledgePresenter::job($action->retry($this->actor($request), $job))], 202);
    }

    /**
     * Resume a paused ingestion with a confirmed maximum cost (`max_cost`, USD).
     */
    public function resume(Request $request, RagJob $job, ControlJob $action): JsonResponse
    {
        return response()->json(['data' => KnowledgePresenter::job($action->resume($this->actor($request), $job, $request->all()))], 202);
    }
}
