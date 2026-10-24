<?php

namespace App\Http\Controllers\Admin\Ai;

use App\Ai\AiService;
use App\Http\Controllers\Controller;
use App\Models\AiUsageLog;
use App\Settings\SettingsManager;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

class AiUsageController extends Controller
{
    public function __construct(
        protected SettingsManager $settings,
        protected AiService $ai,
    ) {}

    /**
     * Display the usage and costs dashboard.
     */
    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'period' => ['nullable', 'in:7d,30d,month,last_month,90d'],
            'provider' => ['nullable', 'string', 'max:50'],
        ]);

        $period = $filters['period'] ?? '30d';
        [$from, $to] = $this->range($period);

        $base = fn (): Builder => AiUsageLog::query()
            ->whereBetween('created_at', [$from, $to])
            ->when($filters['provider'] ?? null, fn ($query, string $provider) => $query->where('provider', $provider));

        $totals = $base()->selectRaw('count(*) as calls, coalesce(sum(cost), 0) as cost, coalesce(sum(input_tokens), 0) as input_tokens, coalesce(sum(output_tokens), 0) as output_tokens, coalesce(sum(cache_read_tokens), 0) as cache_read_tokens, sum(case when priced then 0 else 1 end) as unpriced_calls')->first();

        return Inertia::render('admin/ai/Usage', [
            'filters' => ['period' => $period, 'provider' => $filters['provider'] ?? null],
            'totals' => [
                'calls' => (int) $totals?->getAttribute('calls'),
                'cost' => (float) $totals?->getAttribute('cost'),
                'input_tokens' => (int) $totals?->getAttribute('input_tokens'),
                'output_tokens' => (int) $totals?->getAttribute('output_tokens'),
                'cache_read_tokens' => (int) $totals?->getAttribute('cache_read_tokens'),
                'unpriced_calls' => (int) $totals?->getAttribute('unpriced_calls'),
            ],
            'daily' => $this->daily($base(), $from, $to),
            'byModel' => $base()
                ->selectRaw('provider, model, count(*) as calls, sum(cost) as cost, sum(input_tokens) as input_tokens, sum(output_tokens) as output_tokens, min(case when priced then 1 else 0 end) as priced')
                ->groupBy('provider', 'model')
                ->orderByDesc('cost')
                ->get()
                ->map(fn (AiUsageLog $row) => [
                    'provider' => $row->provider,
                    'model' => $row->model,
                    'calls' => (int) $row->getAttribute('calls'),
                    'cost' => (float) $row->cost,
                    'input_tokens' => (int) $row->input_tokens,
                    'output_tokens' => (int) $row->output_tokens,
                    'priced' => (bool) $row->priced,
                ]),
            'byUser' => $base()
                ->with('user:id,name,email')
                ->selectRaw('user_id, count(*) as calls, sum(cost) as cost, sum(input_tokens + output_tokens) as tokens')
                ->groupBy('user_id')
                ->orderByDesc('cost')
                ->limit(10)
                ->get()
                ->map(fn (AiUsageLog $row) => [
                    'user' => $row->user?->only(['id', 'name', 'email']),
                    'calls' => (int) $row->getAttribute('calls'),
                    'cost' => (float) $row->cost,
                    'tokens' => (int) $row->getAttribute('tokens'),
                ]),
            'recent' => $base()
                ->with(['user:id,name', 'prompt:id,name'])
                ->latest('id')
                ->paginate(20)
                ->withQueryString()
                ->through(fn (AiUsageLog $log) => [
                    ...$log->only(['id', 'provider', 'model', 'source', 'input_tokens', 'output_tokens', 'cache_read_tokens', 'reasoning_tokens', 'priced', 'created_at']),
                    'cost' => (float) $log->cost,
                    'agent' => class_basename($log->agent),
                    'user' => $log->user?->name,
                    'prompt' => $log->prompt?->name,
                ]),
            'providers' => AiUsageLog::query()->distinct()->orderBy('provider')->pluck('provider'),
            'budget' => [
                ...$this->settings->group('ai'),
                'month_spend' => $this->ai->monthlySpend(),
            ],
        ]);
    }

    /**
     * Update the AI budget settings.
     */
    public function updateSettings(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'monthly_budget' => ['nullable', 'numeric', 'min:0'],
            'user_monthly_budget' => ['nullable', 'numeric', 'min:0'],
            'enforce_budget' => ['required', 'boolean'],
            'generate_titles' => ['required', 'boolean'],
        ]);

        $this->settings->setGroup('ai', $validated);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Settings saved.')]);

        return back();
    }

    /**
     * Get the totals per day, including the days without usage.
     *
     * @param  Builder<AiUsageLog>  $query
     * @return list<array{day: string, calls: int, cost: float, tokens: int}>
     */
    protected function daily(Builder $query, Carbon $from, Carbon $to): array
    {
        $rows = $query
            ->selectRaw('DATE(created_at) as day, count(*) as calls, sum(cost) as cost, sum(input_tokens + output_tokens) as tokens')
            ->groupBy('day')
            ->get()
            ->keyBy(fn ($row) => (string) $row->getAttribute('day'));

        $days = [];

        for ($day = $from->copy()->startOfDay(); $day->lte($to); $day = $day->addDay()) {
            $row = $rows->get($day->toDateString());

            $days[] = [
                'day' => $day->toDateString(),
                'calls' => (int) $row?->getAttribute('calls'),
                'cost' => (float) $row?->getAttribute('cost'),
                'tokens' => (int) $row?->getAttribute('tokens'),
            ];
        }

        return $days;
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    protected function range(string $period): array
    {
        $now = Carbon::now();

        return match ($period) {
            '7d' => [$now->copy()->subDays(6)->startOfDay(), $now],
            'month' => [$now->copy()->startOfMonth(), $now],
            'last_month' => [$now->copy()->subMonthNoOverflow()->startOfMonth(), $now->copy()->subMonthNoOverflow()->endOfMonth()],
            '90d' => [$now->copy()->subDays(89)->startOfDay(), $now],
            default => [$now->copy()->subDays(29)->startOfDay(), $now],
        };
    }
}
