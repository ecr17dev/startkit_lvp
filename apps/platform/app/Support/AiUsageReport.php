<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Starter\Ai\Budgets\BudgetManager;
use Starter\Ai\Models\AiRequest;
use Starter\Ai\Models\Budget;

/**
 * Aggregates of the usage ledger for the dashboard and the API. Unknown
 * costs are counted apart, never added as zero; estimates are labelled.
 */
class AiUsageReport
{
    public function __construct(
        public CarbonImmutable $from,
        public CarbonImmutable $to,
    ) {}

    public static function forRange(?string $from, ?string $to, int $defaultDays = 30): self
    {
        $end = $to !== null ? CarbonImmutable::parse($to)->endOfDay() : CarbonImmutable::now()->endOfDay();
        $start = $from !== null ? CarbonImmutable::parse($from)->startOfDay() : $end->subDays($defaultDays - 1)->startOfDay();

        return new self($start->min($end), $end);
    }

    /**
     * @return Builder<AiRequest>
     */
    public function query(): Builder
    {
        return AiRequest::query()->whereBetween('created_at', [$this->from, $this->to]);
    }

    /**
     * @return array<string, mixed>
     */
    public function totals(): array
    {
        $row = $this->query()->selectRaw("
            count(*) as requests,
            coalesce(sum(cost), 0) as cost,
            coalesce(sum(case when cost_status = 'estimated' then estimated_cost else 0 end), 0) as estimated_cost,
            sum(case when cost_status = 'unknown' then 1 else 0 end) as unknown_cost_requests,
            sum(case when status in ('failed', 'interrupted') then 1 else 0 end) as errors,
            sum(case when status = 'blocked' then 1 else 0 end) as blocked,
            coalesce(sum(input_tokens), 0) as input_tokens,
            coalesce(sum(output_tokens), 0) as output_tokens,
            coalesce(sum(cache_read_tokens), 0) as cache_read_tokens,
            avg(latency_ms) as avg_latency_ms,
            percentile_cont(0.5) within group (order by latency_ms) as p50_latency_ms,
            percentile_cont(0.95) within group (order by latency_ms) as p95_latency_ms,
            avg(time_to_first_token_ms) as avg_ttft_ms
        ")->toBase()->first();

        $requests = (int) ($row->requests ?? 0);

        return [
            'requests' => $requests,
            'cost' => (string) ($row->cost ?? '0'),
            'estimated_cost' => (string) ($row->estimated_cost ?? '0'),
            'unknown_cost_requests' => (int) ($row->unknown_cost_requests ?? 0),
            'errors' => (int) ($row->errors ?? 0),
            'blocked' => (int) ($row->blocked ?? 0),
            'error_rate' => $requests > 0 ? round(((int) $row->errors) / $requests * 100, 2) : 0,
            'input_tokens' => (int) ($row->input_tokens ?? 0),
            'output_tokens' => (int) ($row->output_tokens ?? 0),
            'cache_read_tokens' => (int) ($row->cache_read_tokens ?? 0),
            'avg_latency_ms' => $row->avg_latency_ms !== null ? (int) round((float) $row->avg_latency_ms) : null,
            'p50_latency_ms' => $row->p50_latency_ms !== null ? (int) round((float) $row->p50_latency_ms) : null,
            'p95_latency_ms' => $row->p95_latency_ms !== null ? (int) round((float) $row->p95_latency_ms) : null,
            'avg_ttft_ms' => $row->avg_ttft_ms !== null ? (int) round((float) $row->avg_ttft_ms) : null,
        ];
    }

    /**
     * One row per day of the range (days without usage included).
     *
     * @return list<array{date: string, cost: string, requests: int, tokens: int, unknown: int}>
     */
    public function daily(): array
    {
        $rows = $this->query()
            ->selectRaw("to_char(date_trunc('day', created_at), 'YYYY-MM-DD') as day, coalesce(sum(cost), 0) as cost, count(*) as requests, coalesce(sum(input_tokens + output_tokens), 0) as tokens, sum(case when cost_status = 'unknown' then 1 else 0 end) as unknown")
            ->groupByRaw("date_trunc('day', created_at)")
            ->toBase()
            ->get()
            ->keyBy('day');

        $days = [];

        for ($day = $this->from; $day->lte($this->to); $day = $day->addDay()) {
            $row = $rows->get($day->toDateString());
            $days[] = [
                'date' => $day->toDateString(),
                'cost' => (string) ($row->cost ?? '0'),
                'requests' => (int) ($row->requests ?? 0),
                'tokens' => (int) ($row->tokens ?? 0),
                'unknown' => (int) ($row->unknown ?? 0),
            ];
        }

        return $days;
    }

    /**
     * Breakdown by a dimension: feature, model, provider, connection, user or agent.
     *
     * @return list<array{key: string|null, label: string, requests: int, tokens: int, cost: string, unknown: int, errors: int}>
     */
    public function breakdown(string $dimension, int $limit = 10): array
    {
        $query = DB::table('ai_requests as r')->whereBetween('r.created_at', [$this->from, $this->to]);

        [$key, $label] = match ($dimension) {
            'connection' => ['r.connection_id', 'c.label'],
            'user' => ['r.user_id', 'u.name'],
            'model' => ['r.model', 'r.model'],
            'provider' => ['r.provider', 'r.provider'],
            'agent' => ['r.agent', 'r.agent'],
            default => ['r.feature', 'r.feature'],
        };

        if ($dimension === 'connection') {
            $query->leftJoin('ai_provider_connections as c', 'c.id', '=', 'r.connection_id');
        }

        if ($dimension === 'user') {
            $query->leftJoin('users as u', 'u.id', '=', 'r.user_id');
        }

        return array_values($query
            ->selectRaw("{$key} as key, max({$label}) as label, count(*) as requests, coalesce(sum(r.input_tokens + r.output_tokens), 0) as tokens, coalesce(sum(r.cost), 0) as cost, sum(case when r.cost_status = 'unknown' then 1 else 0 end) as unknown, sum(case when r.status in ('failed', 'interrupted') then 1 else 0 end) as errors")
            ->groupBy($key)
            ->orderByRaw('coalesce(sum(r.cost), 0) desc, count(*) desc')
            ->limit($limit)
            ->get()
            ->map(fn (object $row) => [
                'key' => $row->key !== null ? (string) $row->key : null,
                'label' => $row->label !== null ? (string) ($dimension === 'agent' ? class_basename((string) $row->label) : $row->label) : '—',
                'requests' => (int) $row->requests,
                'tokens' => (int) $row->tokens,
                'cost' => (string) $row->cost,
                'unknown' => (int) $row->unknown,
                'errors' => (int) $row->errors,
            ])
            ->all());
    }

    /**
     * @return list<array{type: string, count: int}>
     */
    public function errors(): array
    {
        return array_values($this->query()
            ->whereNotNull('error_type')
            ->selectRaw('error_type, count(*) as count')
            ->groupBy('error_type')
            ->orderByDesc('count')
            ->toBase()
            ->get()
            ->map(fn (object $row) => ['type' => (string) $row->error_type, 'count' => (int) $row->count])
            ->all());
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function budgets(): array
    {
        $manager = app(BudgetManager::class);

        return array_values(Budget::query()->active()->orderBy('name')->get()
            ->map(fn (Budget $budget) => [
                'id' => $budget->id,
                'name' => $budget->name,
                'scope' => $budget->scope->value,
                'scope_value' => $budget->scope_value,
                'period' => $budget->period->value,
                'hard_limit' => $budget->hard_limit,
                ...$manager->status($budget),
            ])
            ->all());
    }

    /**
     * @return array<string, mixed>
     */
    public static function request(AiRequest $request): array
    {
        return [
            'id' => $request->id,
            'created_at' => $request->created_at->toIso8601String(),
            'feature' => $request->feature->value,
            'status' => $request->status->value,
            'user' => $request->relationLoaded('user') && $request->user !== null ? ['id' => $request->user->getKey(), 'name' => $request->user->getAttribute('name')] : null,
            'connection' => $request->relationLoaded('connection') ? $request->connection?->label : null,
            'provider' => $request->provider,
            'model' => $request->model,
            'agent' => $request->agent !== null ? class_basename($request->agent) : null,
            'input_tokens' => $request->input_tokens,
            'output_tokens' => $request->output_tokens,
            'cost' => $request->cost !== null ? (string) $request->cost : null,
            'estimated_cost' => $request->estimated_cost !== null ? (string) $request->estimated_cost : null,
            'reconciled_cost' => $request->reconciled_cost !== null ? (string) $request->reconciled_cost : null,
            'cost_status' => $request->cost_status->value,
            'attempts' => $request->attempts_count,
            'latency_ms' => $request->latency_ms,
            'error_type' => $request->error_type,
        ];
    }
}
