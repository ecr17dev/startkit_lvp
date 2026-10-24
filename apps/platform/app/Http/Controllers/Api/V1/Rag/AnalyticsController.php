<?php

namespace App\Http\Controllers\Api\V1\Rag;

use App\Actions\Knowledge\ManageEvaluations;
use App\Http\Controllers\Admin\Knowledge\EvaluationController as PanelEvaluations;
use App\Http\Controllers\Controller;
use App\Support\KnowledgeAnalytics;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Starter\Knowledge\Models\Collection;
use Starter\Knowledge\Models\EvaluationResult;
use Starter\Knowledge\Models\EvaluationRun;
use Starter\Knowledge\Models\EvaluationSet;
use Starter\Knowledge\Support\Access;

#[Group('Knowledge (RAG)', weight: 60)]
class AnalyticsController extends Controller
{
    /**
     * Knowledge analytics derived from real events: documents by status,
     * ingestion, queue and stage durations, errors, embedding and query
     * costs, retrieval latencies, outcomes, citations, feedback, freshness
     * and the latest evaluations. Requires `rag.analytics.read`.
     */
    public function analytics(Request $request): JsonResponse
    {
        Gate::authorize('rag.analytics.read');
        $user = $this->actor($request);

        $data = $request->validate([
            /** Window in days: 7, 30 or 90. */
            'days' => ['nullable', 'integer', 'in:7,30,90'],
            'collection_id' => ['nullable', 'integer'],
        ]);

        $ids = array_values(array_map('intval', Collection::query()->get()->filter(fn (Collection $collection) => Access::canSeeCollection($user, $collection))->pluck('id')->all()));

        if (isset($data['collection_id'])) {
            $ids = array_values(array_intersect($ids, [(int) $data['collection_id']]));
        }

        return response()->json(['data' => KnowledgeAnalytics::report($ids === [] ? [0] : $ids, (int) ($data['days'] ?? 30))]);
    }

    /**
     * Evaluation sets with their latest run.
     */
    public function sets(): JsonResponse
    {
        Gate::authorize('rag.playground.use');

        return response()->json(['data' => EvaluationSet::query()->withCount('items')->orderBy('name')->get()->map(fn (EvaluationSet $set) => [
            'id' => $set->id,
            'name' => $set->name,
            'collection_ids' => $set->collection_ids,
            'items' => $set->items_count,
            'latest' => PanelEvaluations::present($set->runs()->latest()->first()),
        ])]);
    }

    /**
     * Evaluate a set with retrieval settings (method, top_k, min_score,
     * max_context_tokens). Responds 202 with the run id; metrics: recall@k,
     * MRR, hit rate, no result rate, latency and cost.
     */
    public function evaluate(Request $request, EvaluationSet $set, ManageEvaluations $action): JsonResponse
    {
        $run = $action->run($this->actor($request), $set, $request->all());

        return response()->json(['data' => PanelEvaluations::present($run->refresh())], 202);
    }

    /**
     * An evaluation run with its metrics and per question results.
     */
    public function evaluation(EvaluationRun $run): JsonResponse
    {
        Gate::authorize('rag.playground.use');

        return response()->json(['data' => [
            ...(PanelEvaluations::present($run) ?? []),
            'results' => $run->results()->with('item')->get()->map(fn (EvaluationResult $result) => [
                'item_id' => $result->item_id,
                'question' => $result->item->question,
                'expected_document_ids' => $result->item->expected_document_ids,
                'retrieved_document_ids' => $result->retrieved_document_ids,
                'first_relevant_rank' => $result->first_relevant_rank,
                'recall' => $result->recall,
                'reciprocal_rank' => $result->reciprocal_rank,
                'latency_ms' => $result->latency_ms,
            ]),
        ]]);
    }
}
