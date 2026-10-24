<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Starter\Ai\Enums\Feature;
use Starter\Knowledge\Enums\JobStatus;
use Starter\Knowledge\Models\Collection;
use Starter\Knowledge\Models\EvaluationRun;
use Starter\Knowledge\Models\Source;

/**
 * Knowledge dashboard. Every figure is derived from stored events:
 * documents and versions, jobs (stages, errors), the AI ledger (embedding
 * and query costs) and the retrieval traces (latencies, outcomes,
 * citations, feedback). Operational indicators are kept apart from the
 * quality ones (evaluation sets, human judgements and feedback).
 */
class KnowledgeAnalytics
{
    /**
     * @param  list<int>  $collectionIds  collections visible to the viewer
     * @return array<string, mixed>
     */
    public static function report(array $collectionIds, int $days = 30): array
    {
        $to = CarbonImmutable::now()->endOfDay();
        $from = $to->subDays($days - 1)->startOfDay();

        return [
            'window' => ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'days' => $days],
            'totals' => self::totals($collectionIds, $from),
            'documents_by_status' => self::documentsByStatus($collectionIds),
            'ingestion_daily' => self::ingestionDaily($collectionIds, $from, $days),
            'collections' => self::collections($collectionIds),
            'queue' => self::queue($collectionIds, $from),
            'errors' => self::errors($collectionIds, $from),
            'costs_daily' => self::costsDaily($collectionIds, $from, $days),
            'queries_daily' => self::queriesDaily($collectionIds, $from, $days),
            'outcomes' => self::outcomes($collectionIds, $from),
            'unanswered' => self::unanswered($collectionIds, $from),
            'agents' => self::agents($collectionIds, $from),
            'freshness' => self::freshness($collectionIds),
            'evaluations' => self::evaluations(),
        ];
    }

    /**
     * Runs touching the collections (their id list overlaps).
     *
     * @param  list<int>  $collectionIds
     */
    protected static function runsQuery(array $collectionIds): Builder
    {
        // collection_ids is a JSON array of numbers...
        return DB::table('rag_retrieval_runs')->whereRaw(
            'EXISTS (SELECT 1 FROM jsonb_array_elements_text(rag_retrieval_runs.collection_ids) AS c(id) WHERE c.id::bigint = ANY(?::bigint[]))',
            ['{'.implode(',', $collectionIds).'}'],
        );
    }

    /**
     * @param  list<int>  $collectionIds
     * @return array<string, mixed>
     */
    protected static function totals(array $collectionIds, CarbonImmutable $from): array
    {
        $documents = DB::table('rag_documents')->whereIn('collection_id', $collectionIds)->whereNull('deleted_at');
        $runs = self::runsQuery($collectionIds)->where('created_at', '>=', $from);
        $latency = (clone $runs)->selectRaw('avg(latency_ms) AS avg, percentile_cont(0.95) WITHIN GROUP (ORDER BY latency_ms) AS p95, count(*) AS total, count(*) FILTER (WHERE hits_count = 0) AS empty, count(*) FILTER (WHERE outcome = ?) AS abstained', ['abstained'])->first();
        $costs = self::ledger($collectionIds)->where('created_at', '>=', $from)
            ->selectRaw('feature, coalesce(sum(cost), 0) AS cost, count(*) FILTER (WHERE cost IS NULL) AS unknown')->groupBy('feature')->get()->keyBy('feature');
        $total = (int) ($latency->total ?? 0);

        return [
            'collections' => count($collectionIds),
            'sources' => Source::query()->whereIn('collection_id', $collectionIds)->count(),
            // Total, processed (finished, whatever the result) and eligible (searchable)...
            'documents' => (clone $documents)->count(),
            'processed' => (clone $documents)->whereIn('status', ['indexed', 'failed', 'empty', 'duplicate'])->count(),
            'indexed' => (clone $documents)->where('status', 'indexed')->count(),
            'chunks' => (int) Collection::query()->whereIn('rag_collections.id', $collectionIds)->join('rag_index_versions', 'rag_index_versions.id', '=', 'rag_collections.active_index_version_id')->sum('rag_index_versions.chunks'),
            'bytes' => (int) (clone $documents)->sum('size'),
            'queries' => $total,
            'avg_latency_ms' => (int) round((float) ($latency->avg ?? 0)),
            'p95_latency_ms' => (int) round((float) ($latency->p95 ?? 0)),
            'no_result_rate' => $total > 0 ? round((int) $latency->empty / $total, 4) : 0.0,
            'abstention_rate' => $total > 0 ? round((int) $latency->abstained / $total, 4) : 0.0,
            'embedding_cost' => (string) ($costs[Feature::Embedding->value]->cost ?? '0'),
            'query_cost' => (string) ($costs[Feature::Rag->value]->cost ?? '0'),
            'unknown_cost_calls' => (int) ($costs[Feature::Embedding->value]->unknown ?? 0) + (int) ($costs[Feature::Rag->value]->unknown ?? 0),
        ];
    }

    /**
     * Ledger requests of the knowledge features for the collections (query
     * embeddings are attributed through their retrieval run).
     *
     * @param  list<int>  $collectionIds
     */
    protected static function ledger(array $collectionIds): Builder
    {
        $ids = '{'.implode(',', array_map('strval', $collectionIds)).'}';

        return DB::table('ai_requests')
            ->whereIn('feature', [Feature::Embedding->value, Feature::Rag->value])
            ->where(fn ($query) => $query
                ->whereRaw("(metadata->>'collection_id')::bigint = ANY(?::bigint[])", [$ids])
                ->orWhereIn('id', self::runsQuery($collectionIds)->whereNotNull('ai_request_id')->select('ai_request_id')));
    }

    /**
     * @param  list<int>  $collectionIds
     * @return list<array{source_id: int, source: string, collection: string, counts: array<string, int>, total: int}>
     */
    protected static function documentsByStatus(array $collectionIds): array
    {
        $rows = DB::table('rag_documents')
            ->join('rag_sources', 'rag_sources.id', '=', 'rag_documents.source_id')
            ->join('rag_collections', 'rag_collections.id', '=', 'rag_documents.collection_id')
            ->whereIn('rag_documents.collection_id', $collectionIds)
            ->whereNull('rag_documents.deleted_at')
            ->selectRaw('rag_sources.id AS source_id, rag_sources.name AS source, rag_collections.name AS collection, rag_documents.status, count(*) AS total')
            ->groupBy('rag_sources.id', 'rag_sources.name', 'rag_collections.name', 'rag_documents.status')
            ->get();

        $sources = [];

        foreach ($rows as $row) {
            $id = (int) $row->source_id;
            $sources[$id] ??= ['source_id' => $id, 'source' => (string) $row->source, 'collection' => (string) $row->collection, 'counts' => [], 'total' => 0];
            $sources[$id]['counts'][(string) $row->status] = (int) $row->total;
            $sources[$id]['total'] += (int) $row->total;
        }

        return array_values($sources);
    }

    /**
     * Documents indexed and versions extracted per day.
     *
     * @param  list<int>  $collectionIds
     * @return list<array{day: string, indexed: int, versions: int}>
     */
    protected static function ingestionDaily(array $collectionIds, CarbonImmutable $from, int $days): array
    {
        $indexed = DB::table('rag_documents')->whereIn('collection_id', $collectionIds)->where('indexed_at', '>=', $from)
            ->selectRaw('date(indexed_at) AS day, count(*) AS total')->groupBy('day')->pluck('total', 'day');
        $versions = DB::table('rag_document_versions')->join('rag_documents', 'rag_documents.id', '=', 'rag_document_versions.document_id')
            ->whereIn('rag_documents.collection_id', $collectionIds)->where('rag_document_versions.created_at', '>=', $from)
            ->selectRaw('date(rag_document_versions.created_at) AS day, count(*) AS total')->groupBy('day')->pluck('total', 'day');

        return array_map(fn (string $day) => ['day' => $day, 'indexed' => (int) ($indexed[$day] ?? 0), 'versions' => (int) ($versions[$day] ?? 0)], self::days($from, $days));
    }

    /**
     * @param  list<int>  $collectionIds
     * @return list<array<string, mixed>>
     */
    protected static function collections(array $collectionIds): array
    {
        return array_values(Collection::query()->with('activeVersion')->whereIn('id', $collectionIds)->orderBy('name')->get()->map(fn (Collection $collection) => [
            'id' => $collection->id,
            'name' => $collection->name,
            'documents' => $collection->documents()->alive()->count(),
            'chunks' => $collection->activeVersion->chunks ?? 0,
            'tokens' => $collection->activeVersion->tokens ?? 0,
            'bytes' => (int) $collection->documents()->alive()->sum('size'),
        ])->all());
    }

    /**
     * Queue state and average time per stage of finished jobs.
     *
     * @param  list<int>  $collectionIds
     * @return array{by_status: array<string, int>, stages: list<array{stage: string, avg_ms: int, jobs: int}>, open: int}
     */
    protected static function queue(array $collectionIds, CarbonImmutable $from): array
    {
        $jobs = DB::table('rag_jobs')->whereIn('collection_id', $collectionIds)->where('created_at', '>=', $from);
        $byStatus = (clone $jobs)->selectRaw('status, count(*) AS total')->groupBy('status')->pluck('total', 'status')->map(fn ($total) => (int) $total)->all();
        $stages = (clone $jobs)->whereNotNull('timings')
            ->selectRaw('t.key AS stage, avg((t.value)::bigint) AS avg_ms, count(*) AS jobs')
            ->crossJoin(DB::raw('jsonb_each_text(timings) AS t'))
            ->groupBy('t.key')
            ->get();

        return [
            'by_status' => $byStatus,
            'open' => DB::table('rag_jobs')->whereIn('collection_id', $collectionIds)->whereIn('status', JobStatus::open())->count(),
            'stages' => array_values($stages->map(fn ($row) => ['stage' => (string) $row->stage, 'avg_ms' => (int) round((float) $row->avg_ms), 'jobs' => (int) $row->jobs])->sortByDesc('avg_ms')->values()->all()),
        ];
    }

    /**
     * Failed documents by parser/type, failed jobs by error type and
     * failed provider calls (embeddings and queries) by provider.
     *
     * @param  list<int>  $collectionIds
     * @return array{by_type: list<array{label: string, total: int}>, by_job: list<array{label: string, total: int}>, by_provider: list<array{label: string, total: int}>}
     */
    protected static function errors(array $collectionIds, CarbonImmutable $from): array
    {
        $byType = DB::table('rag_documents')->whereIn('collection_id', $collectionIds)->whereNull('deleted_at')->whereIn('status', ['failed', 'empty'])
            ->selectRaw("coalesce(mime_type, 'unknown') || ' · ' || status AS label, count(*) AS total")->groupBy('label')->orderByDesc('total')->get();
        $byJob = DB::table('rag_jobs')->whereIn('collection_id', $collectionIds)->where('created_at', '>=', $from)->where('status', 'failed')
            ->selectRaw("type || ' · ' || coalesce(error_type, 'error') AS label, count(*) AS total")->groupBy('label')->orderByDesc('total')->get();
        $byProvider = self::ledger($collectionIds)->where('created_at', '>=', $from)->whereIn('status', ['failed', 'interrupted'])
            ->selectRaw("coalesce(provider, 'unknown') || ' · ' || coalesce(error_type, status) AS label, count(*) AS total")->groupBy('label')->orderByDesc('total')->get();

        return [
            'by_type' => array_values($byType->map(fn ($row) => ['label' => (string) $row->label, 'total' => (int) $row->total])->all()),
            'by_job' => array_values($byJob->map(fn ($row) => ['label' => (string) $row->label, 'total' => (int) $row->total])->all()),
            'by_provider' => array_values($byProvider->map(fn ($row) => ['label' => (string) $row->label, 'total' => (int) $row->total])->all()),
        ];
    }

    /**
     * @param  list<int>  $collectionIds
     * @return list<array{day: string, embedding: float, rag: float, unknown: int}>
     */
    protected static function costsDaily(array $collectionIds, CarbonImmutable $from, int $days): array
    {
        $rows = self::ledger($collectionIds)->where('created_at', '>=', $from)
            ->selectRaw('date(created_at) AS day, feature, coalesce(sum(cost), 0) AS cost, count(*) FILTER (WHERE cost IS NULL) AS unknown')
            ->groupBy('day', 'feature')->get();

        $byDay = [];

        foreach ($rows as $row) {
            $byDay[(string) $row->day][(string) $row->feature] = ['cost' => (float) $row->cost, 'unknown' => (int) $row->unknown];
        }

        return array_map(fn (string $day) => [
            'day' => $day,
            'embedding' => $byDay[$day][Feature::Embedding->value]['cost'] ?? 0.0,
            'rag' => $byDay[$day][Feature::Rag->value]['cost'] ?? 0.0,
            'unknown' => ($byDay[$day][Feature::Embedding->value]['unknown'] ?? 0) + ($byDay[$day][Feature::Rag->value]['unknown'] ?? 0),
        ], self::days($from, $days));
    }

    /**
     * @param  list<int>  $collectionIds
     * @return list<array{day: string, queries: int, avg_latency_ms: int, p95_latency_ms: int, empty: int, abstained: int}>
     */
    protected static function queriesDaily(array $collectionIds, CarbonImmutable $from, int $days): array
    {
        $rows = self::runsQuery($collectionIds)->where('created_at', '>=', $from)
            ->selectRaw('date(created_at) AS day, count(*) AS queries, avg(latency_ms) AS avg, percentile_cont(0.95) WITHIN GROUP (ORDER BY latency_ms) AS p95, count(*) FILTER (WHERE hits_count = 0) AS empty, count(*) FILTER (WHERE outcome = ?) AS abstained', ['abstained'])
            ->groupBy('day')->get()->keyBy('day');

        return array_map(fn (string $day) => [
            'day' => $day,
            'queries' => (int) ($rows[$day]->queries ?? 0),
            'avg_latency_ms' => (int) round((float) ($rows[$day]->avg ?? 0)),
            'p95_latency_ms' => (int) round((float) ($rows[$day]->p95 ?? 0)),
            'empty' => (int) ($rows[$day]->empty ?? 0),
            'abstained' => (int) ($rows[$day]->abstained ?? 0),
        ], self::days($from, $days));
    }

    /**
     * Outcomes of grounded answers (chat, tool and API turns).
     *
     * @param  list<int>  $collectionIds
     * @return array<string, int>
     */
    protected static function outcomes(array $collectionIds, CarbonImmutable $from): array
    {
        return self::runsQuery($collectionIds)->where('created_at', '>=', $from)->whereNotNull('outcome')
            ->selectRaw('outcome, count(*) AS total')->groupBy('outcome')->pluck('total', 'outcome')->map(fn ($total) => (int) $total)->all();
    }

    /**
     * Questions without results or abstained: missing knowledge.
     *
     * @param  list<int>  $collectionIds
     * @return list<array{query: string, total: int, last_at: string}>
     */
    protected static function unanswered(array $collectionIds, CarbonImmutable $from): array
    {
        return array_values(self::runsQuery($collectionIds)->where('created_at', '>=', $from)
            ->whereIn('surface', ['chat', 'tool', 'api'])
            ->where(fn ($query) => $query->where('hits_count', 0)->orWhere('outcome', 'abstained'))
            ->selectRaw('lower(trim(query)) AS normalized, min(query) AS query, count(*) AS total, max(created_at) AS last_at')
            ->groupBy('normalized')->orderByDesc('total')->orderByDesc('last_at')->limit(10)->get()
            ->map(fn ($row) => ['query' => (string) $row->query, 'total' => (int) $row->total, 'last_at' => (string) $row->last_at])->all());
    }

    /**
     * Grounded answers per agent: citations and feedback.
     *
     * @param  list<int>  $collectionIds
     * @return list<array<string, mixed>>
     */
    protected static function agents(array $collectionIds, CarbonImmutable $from): array
    {
        return array_values(self::runsQuery($collectionIds)->where('rag_retrieval_runs.created_at', '>=', $from)
            ->whereNotNull('rag_retrieval_runs.agent_version_id')
            ->join('ai_agent_versions', 'ai_agent_versions.id', '=', 'rag_retrieval_runs.agent_version_id')
            ->join('ai_agents', 'ai_agents.id', '=', 'ai_agent_versions.agent_id')
            ->selectRaw("ai_agents.name AS agent, count(*) AS answers, avg(citations_count) AS avg_citations, sum(invalid_citations) AS invalid,
                count(*) FILTER (WHERE outcome = 'unsupported') AS unsupported, count(*) FILTER (WHERE outcome = 'abstained') AS abstained,
                count(*) FILTER (WHERE feedback > 0) AS positive, count(*) FILTER (WHERE feedback < 0) AS negative")
            ->groupBy('ai_agents.name')->orderByDesc('answers')->get()
            ->map(fn ($row) => [
                'agent' => (string) $row->agent,
                'answers' => (int) $row->answers,
                'avg_citations' => round((float) $row->avg_citations, 2),
                'invalid' => (int) $row->invalid,
                'unsupported' => (int) $row->unsupported,
                'abstained' => (int) $row->abstained,
                'positive' => (int) $row->positive,
                'negative' => (int) $row->negative,
            ])->all());
    }

    /**
     * Age and changes of every source (last sync).
     *
     * @param  list<int>  $collectionIds
     * @return list<array<string, mixed>>
     */
    protected static function freshness(array $collectionIds): array
    {
        return array_values(Source::query()->with('collection')->whereIn('collection_id', $collectionIds)->orderBy('last_sync_at')->get()->map(function (Source $source) {
            $raw = $source->last_job_id !== null ? DB::table('rag_jobs')->where('id', $source->last_job_id)->value('units') : null;
            $units = is_string($raw) ? (array) json_decode($raw, true) : [];

            return [
                'source' => $source->name,
                'collection' => $source->collection->name,
                'type' => $source->type->value,
                'schedule' => $source->schedule,
                'last_sync_at' => $source->last_sync_at?->toIso8601String(),
                'age_hours' => $source->last_sync_at !== null ? (int) round($source->last_sync_at->diffInHours(now())) : null,
                'changed' => (int) ($units['changed'] ?? 0),
                'deleted' => (int) ($units['deleted'] ?? 0),
                'unchanged' => (int) ($units['unchanged'] ?? 0),
            ];
        })->all());
    }

    /**
     * Latest completed evaluation of every set (semantic quality).
     *
     * @return list<array<string, mixed>>
     */
    protected static function evaluations(): array
    {
        return array_values(EvaluationRun::query()->with('set')->where('status', EvaluationRun::COMPLETED)->latest('finished_at')->get()
            ->unique('set_id')->map(fn (EvaluationRun $run) => [
                'set' => $run->set->name,
                'set_id' => $run->set_id,
                'label' => $run->label,
                'metrics' => $run->metrics,
                'finished_at' => $run->finished_at?->toIso8601String(),
            ])->values()->all());
    }

    /**
     * Every day of the window (Y-m-d).
     *
     * @return list<string>
     */
    protected static function days(CarbonImmutable $from, int $days): array
    {
        $series = [];

        for ($offset = 0; $offset < $days; $offset++) {
            $series[] = $from->addDays($offset)->toDateString();
        }

        return $series;
    }
}
