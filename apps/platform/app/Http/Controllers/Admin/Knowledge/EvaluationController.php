<?php

namespace App\Http\Controllers\Admin\Knowledge;

use App\Actions\Knowledge\ManageEvaluations;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Starter\Knowledge\Models\Collection;
use Starter\Knowledge\Models\Document;
use Starter\Knowledge\Models\EvaluationItem;
use Starter\Knowledge\Models\EvaluationResult;
use Starter\Knowledge\Models\EvaluationRun;
use Starter\Knowledge\Models\EvaluationSet;
use Starter\Knowledge\Support\Access;

/**
 * Evaluation sets: fixed questions with the documents that should be
 * found, and runs comparing retrieval settings.
 */
class EvaluationController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('rag.playground.use');
        $user = $this->actor($request);

        return Inertia::render('admin/knowledge/evaluations/Index', [
            'sets' => EvaluationSet::query()->withCount('items')->orderBy('name')->get()->map(fn (EvaluationSet $set) => [
                'id' => $set->id,
                'name' => $set->name,
                'description' => $set->description,
                'items' => $set->items_count,
                'collections' => Collection::query()->whereIn('id', $set->collection_ids)->pluck('name'),
                'latest' => self::present($set->runs()->latest()->first()),
            ]),
            'collections' => $this->visibleCollections($user),
        ]);
    }

    public function store(Request $request, ManageEvaluations $action): RedirectResponse
    {
        $set = $action->saveSet($this->actor($request), $request->all());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Evaluation set created.')]);

        return to_route('admin.knowledge.evaluations.show', $set);
    }

    public function show(Request $request, EvaluationSet $set): Response
    {
        Gate::authorize('rag.playground.use');
        $user = $this->actor($request);
        $selected = $request->query('run');
        $runs = $set->runs()->latest()->limit(20)->get();
        $current = is_string($selected) ? $runs->firstWhere('id', $selected) : $runs->firstWhere('status', EvaluationRun::COMPLETED);

        $titles = Document::query()->whereIn('collection_id', $set->collection_ids)->pluck('title', 'id');

        return Inertia::render('admin/knowledge/evaluations/Show', [
            'set' => [
                'id' => $set->id,
                'name' => $set->name,
                'description' => $set->description,
                'collection_ids' => $set->collection_ids,
                'collections' => Collection::query()->whereIn('id', $set->collection_ids)->pluck('name'),
            ],
            'items' => $set->items()->orderBy('id')->get()->map(fn (EvaluationItem $item) => [
                'id' => $item->id,
                'question' => $item->question,
                'expected_answer' => $item->expected_answer,
                'expected' => array_map(fn (int $id) => ['id' => $id, 'title' => $titles[$id] ?? "#{$id}"], $item->expected_document_ids),
            ]),
            'runs' => $runs->map(fn (EvaluationRun $run) => self::present($run)),
            'current' => $current !== null ? [
                ...self::present($current),
                'results' => $current->results()->get()->keyBy('item_id')->map(fn (EvaluationResult $result) => [
                    'retrieved' => array_map(fn (int $id) => ['id' => $id, 'title' => $titles[$id] ?? "#{$id}"], $result->retrieved_document_ids),
                    'first_relevant_rank' => $result->first_relevant_rank,
                    'recall' => $result->recall,
                    'reciprocal_rank' => $result->reciprocal_rank,
                    'latency_ms' => $result->latency_ms,
                ]),
            ] : null,
            'collections' => $this->visibleCollections($user),
            'documents' => Document::query()->with('collection', 'source')->whereIn('collection_id', $set->collection_ids)->alive()->where('status', 'indexed')->orderBy('title')->limit(500)->get()
                ->filter(fn (Document $document) => Access::canReadDocument($user, $document))
                ->map(fn (Document $document) => ['id' => $document->id, 'title' => $document->title ?? $document->external_id, 'collection' => $document->collection->name])
                ->values(),
        ]);
    }

    public function update(Request $request, EvaluationSet $set, ManageEvaluations $action): RedirectResponse
    {
        $action->saveSet($this->actor($request), $request->all(), $set);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Evaluation set saved.')]);

        return back();
    }

    public function destroy(EvaluationSet $set): RedirectResponse
    {
        Gate::authorize('rag.playground.use');
        $set->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Evaluation set deleted.')]);

        return to_route('admin.knowledge.evaluations.index');
    }

    public function storeItem(Request $request, EvaluationSet $set, ManageEvaluations $action): RedirectResponse
    {
        $action->saveItem($this->actor($request), $set, $request->all());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Question saved.')]);

        return back();
    }

    public function destroyItem(EvaluationSet $set, EvaluationItem $item): RedirectResponse
    {
        Gate::authorize('rag.playground.use');
        abort_unless($item->set_id === $set->id, 404);
        $item->delete();

        return back();
    }

    public function run(Request $request, EvaluationSet $set, ManageEvaluations $action): RedirectResponse
    {
        $run = $action->run($this->actor($request), $set, $request->all());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Evaluation started.')]);

        return to_route('admin.knowledge.evaluations.show', ['set' => $set, 'run' => $run->id]);
    }

    /**
     * @return \Illuminate\Support\Collection<int, array{id: int, name: string}>
     */
    protected function visibleCollections(User $user): \Illuminate\Support\Collection
    {
        return Collection::query()->where('status', Collection::ACTIVE)->orderBy('name')->get()
            ->filter(fn (Collection $collection) => $collection->isVisibleTo($user))
            ->map(fn (Collection $collection) => ['id' => $collection->id, 'name' => $collection->name])->values();
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function present(?EvaluationRun $run): ?array
    {
        return $run === null ? null : [
            'id' => $run->id,
            'label' => $run->label,
            'settings' => $run->settings,
            'status' => $run->status,
            'metrics' => $run->metrics,
            'error' => $run->error,
            'created_at' => $run->created_at->toIso8601String(),
            'finished_at' => $run->finished_at?->toIso8601String(),
        ];
    }
}
