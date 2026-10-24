<?php

namespace Starter\Knowledge\Evaluation;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Contracts\Auth\Authenticatable;
use Starter\Identity\Identity;
use Starter\Knowledge\Models\Collection;
use Starter\Knowledge\Models\EvaluationItem;
use Starter\Knowledge\Models\EvaluationRun;
use Starter\Knowledge\Retrieval\RetrievalQuery;
use Starter\Knowledge\Retrieval\Retriever;
use Throwable;

/**
 * Runs the questions of a set with the settings of the run, as the user
 * who started it (their access lists apply), and measures:
 *
 * - recall@k: share of the expected documents among the retrieved ones;
 * - reciprocal rank: 1 / rank of the first expected document (MRR);
 * - hit rate: questions with at least one expected document retrieved;
 * - no result rate, latency (average and p95) and cost.
 *
 * These are retrieval metrics on labelled questions, not a judgement of
 * the generated answers (that is the human feedback).
 */
class Evaluator
{
    public function __construct(protected Retriever $retriever) {}

    public function run(EvaluationRun $run): void
    {
        $run->forceFill(['status' => EvaluationRun::RUNNING, 'started_at' => now(), 'error' => null])->save();

        try {
            $user = $run->created_by !== null ? Identity::userModel()::query()->find($run->created_by) : null;
            $set = $run->set;
            $collections = array_values(Collection::query()->whereIn('id', $set->collection_ids)->where('status', Collection::ACTIVE)->get()->all());

            $run->results()->delete();

            foreach ($set->items()->orderBy('id')->get() as $item) {
                $this->evaluate($run, $item, $user instanceof Authenticatable ? $user : null, $collections);
            }

            $run->forceFill(['status' => EvaluationRun::COMPLETED, 'metrics' => $this->metrics($run), 'finished_at' => now()])->save();
        } catch (Throwable $exception) {
            $run->forceFill(['status' => EvaluationRun::FAILED, 'error' => mb_substr($exception->getMessage(), 0, 500), 'finished_at' => now()])->save();
        }
    }

    /**
     * @param  list<Collection>  $collections
     */
    protected function evaluate(EvaluationRun $run, EvaluationItem $item, ?Authenticatable $user, array $collections): void
    {
        $query = RetrievalQuery::for($item->question, $user, $collections, $run->settings, 'evaluation');
        $result = $this->retriever->search($query);

        $retrieved = [];

        foreach ($result->hits as $hit) {
            if (! in_array($hit->documentId, $retrieved, true)) {
                $retrieved[] = $hit->documentId;
            }
        }

        $expected = $item->expected_document_ids;
        $found = array_values(array_intersect($retrieved, $expected));
        $firstRank = null;

        foreach ($retrieved as $position => $documentId) {
            if (in_array($documentId, $expected, true)) {
                $firstRank = $position + 1;

                break;
            }
        }

        $run->results()->create([
            'item_id' => $item->id,
            'retrieval_run_id' => $result->run?->id,
            'retrieved_document_ids' => $retrieved,
            'first_relevant_rank' => $firstRank,
            'recall' => $expected === [] ? 0.0 : count($found) / count($expected),
            'reciprocal_rank' => $firstRank !== null ? 1 / $firstRank : 0.0,
            'latency_ms' => $result->latencyMs,
            'cost' => $result->costKnown ? $result->cost : null,
        ]);
    }

    /**
     * @return array{questions: int, recall_at_k: float, mrr: float, hit_rate: float, no_result_rate: float, avg_latency_ms: int, p95_latency_ms: int, cost: string|null}
     */
    public function metrics(EvaluationRun $run): array
    {
        $results = $run->results()->get();
        $count = max(1, $results->count());
        $latencies = $results->pluck('latency_ms')->sort()->values();
        $cost = BigDecimal::zero();
        $known = true;

        foreach ($results as $result) {
            if ($result->cost === null) {
                $known = false;
            } else {
                $cost = $cost->plus((string) $result->cost);
            }
        }

        return [
            'questions' => $results->count(),
            'recall_at_k' => round($results->avg('recall') ?? 0, 4),
            'mrr' => round($results->avg('reciprocal_rank') ?? 0, 4),
            'hit_rate' => round($results->filter(fn ($result) => $result->first_relevant_rank !== null)->count() / $count, 4),
            'no_result_rate' => round($results->filter(fn ($result) => $result->retrieved_document_ids === [])->count() / $count, 4),
            'avg_latency_ms' => (int) round($latencies->avg() ?? 0),
            'p95_latency_ms' => (int) ($latencies->get((int) max(0, ceil($latencies->count() * 0.95) - 1)) ?? 0),
            'cost' => $known ? (string) $cost->toScale(10, RoundingMode::HalfUp) : null,
        ];
    }
}
