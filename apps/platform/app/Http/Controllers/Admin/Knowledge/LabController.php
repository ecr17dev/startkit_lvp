<?php

namespace App\Http\Controllers\Admin\Knowledge;

use App\Actions\Knowledge\RateKnowledge;
use App\Actions\Knowledge\SearchKnowledge;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Starter\Knowledge\Enums\SearchMethod;
use Starter\Knowledge\Exceptions\RagUnavailableException;
use Starter\Knowledge\Knowledge;
use Starter\Knowledge\Models\Collection;
use Starter\Knowledge\Models\EvaluationSet;
use Starter\Knowledge\Models\RetrievalHit;
use Starter\Knowledge\Models\RetrievalRun;

/**
 * Retrieval lab: write a query and inspect the authorized passages, their
 * ranking, scores, method, latency and cost; compare methods side by side.
 */
class LabController extends Controller
{
    public function index(Request $request, Knowledge $knowledge): Response
    {
        Gate::authorize('rag.playground.use');
        $user = $this->actor($request);

        return Inertia::render('admin/knowledge/Lab', [
            'available' => $knowledge->available(),
            'collections' => Collection::query()->where('status', Collection::ACTIVE)->orderBy('name')->get()
                ->filter(fn (Collection $collection) => $collection->isVisibleTo($user))
                ->map(fn (Collection $collection) => ['id' => $collection->id, 'name' => $collection->name, 'search' => $collection->search, 'language' => $collection->language])
                ->values(),
            'recent' => RetrievalRun::query()->where('user_id', $user->id)->where('surface', 'lab')->latest('created_at')->limit(10)->get()
                ->map(fn (RetrievalRun $run) => [
                    'id' => $run->id,
                    'query' => $run->query,
                    'method' => $run->method,
                    'hits' => $run->hits_count,
                    'latency_ms' => $run->latency_ms,
                    'cost' => $run->cost_known ? (string) $run->cost : null,
                    'created_at' => $run->created_at?->toIso8601String(),
                ]),
        ]);
    }

    /**
     * Relevance judgement of a retrieved passage (human evaluation).
     */
    public function judge(Request $request, RetrievalHit $hit, RateKnowledge $action): RedirectResponse
    {
        $action->hit($this->actor($request), $hit, $request->all());

        return back();
    }

    public function search(Request $request, SearchKnowledge $action): RedirectResponse
    {
        $user = $this->actor($request);

        /** @var array{compare?: list<string>|null} $extra */
        $extra = Validator::make($request->all(), [
            /** Methods to compare side by side (up to 3). */
            'compare' => ['nullable', 'array', 'max:3'],
            'compare.*' => [Rule::enum(SearchMethod::class), 'distinct'],
        ])->validate();

        $methods = $extra['compare'] ?? [];

        try {
            $results = $methods === []
                ? [$action->handle($user, $request->all(), 'lab')]
                : array_map(fn (string $method) => $action->handle($user, [...$request->all(), 'method' => $method], 'lab'), $methods);
        } catch (RagUnavailableException $exception) {
            throw ValidationException::withMessages(['query' => $exception->getMessage()]);
        }

        Inertia::flash('search', [
            'query' => (string) $request->input('query'),
            'results' => array_map(function ($result) {
                $ids = $result->run?->hits()->pluck('id', 'rank') ?? collect();
                $data = $result->toArray();
                $data['hits'] = array_map(fn (array $hit) => [...$hit, 'hit_id' => $ids[$hit['rank']] ?? null], $data['hits']);

                return $data;
            }, $results),
            'sets' => EvaluationSet::query()->orderBy('name')->get(['id', 'name']),
        ]);

        return back();
    }
}
