<?php

namespace App\Http\Controllers\Admin\Ai;

use App\Http\Controllers\Controller;
use App\Support\AiUsageReport;
use App\Support\Paginated;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Starter\Ai\Currency\ExchangeRates;
use Starter\Ai\Enums\CostStatus;
use Starter\Ai\Enums\Feature;
use Starter\Ai\Enums\RequestStatus;
use Starter\Ai\Models\AiRequest;
use Starter\Ai\Models\ExchangeRate;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Usage and cost dashboard. Measurement never stops: hiding the dashboard
 * only hides the page.
 */
class UsageController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $this->filters($request);
        $report = AiUsageReport::forRange($filters['from'] ?? null, $filters['to'] ?? null);

        $requests = $this->ledgerQuery($report, $filters)->with(['user', 'connection'])->latest()->paginate(20)->withQueryString();
        $currency = (string) settings('ai.display_currency', 'USD');
        $rate = $currency !== 'USD' ? ExchangeRate::latestFor($currency) : null;

        return Inertia::render('admin/ai/Usage', [
            'range' => ['from' => $report->from->toDateString(), 'to' => $report->to->toDateString()],
            'filters' => (object) $filters,
            'totals' => $report->totals(),
            'daily' => $report->daily(),
            'breakdowns' => [
                'feature' => $report->breakdown('feature'),
                'connection' => $report->breakdown('connection'),
                'model' => $report->breakdown('model'),
                'user' => $report->breakdown('user'),
                'agent' => $report->breakdown('agent'),
            ],
            'errors' => $report->errors(),
            'budgets' => AiUsageReport::budgets(),
            'requests' => [
                'data' => $requests->getCollection()->map(fn (AiRequest $item) => AiUsageReport::request($item))->values(),
                'meta' => Paginated::meta($requests),
            ],
            'settings' => [
                'dashboard' => (bool) settings('ai.dashboard', true),
                'display_currency' => $currency,
            ],
            'exchangeRate' => $rate !== null ? [
                'currency' => $rate->quote,
                'rate' => (string) $rate->rate,
                'date' => $rate->rate_date->toDateString(),
                'source' => $rate->source,
            ] : null,
            'features' => array_map(fn (Feature $feature) => $feature->value, Feature::cases()),
            'statuses' => array_map(fn (RequestStatus $status) => $status->value, RequestStatus::cases()),
            'costStatuses' => array_map(fn (CostStatus $status) => $status->value, CostStatus::cases()),
        ]);
    }

    /**
     * Export the ledger rows of the range as CSV (table equivalent of the charts).
     */
    public function export(Request $request): StreamedResponse
    {
        $filters = $this->filters($request);
        $report = AiUsageReport::forRange($filters['from'] ?? null, $filters['to'] ?? null);
        $query = $this->ledgerQuery($report, $filters)->with(['user', 'connection'])->orderBy('created_at');

        audit('ai.usage.exported', null, ['from' => $report->from->toDateString(), 'to' => $report->to->toDateString()]);

        return response()->streamDownload(function () use ($query) {
            $out = fopen('php://output', 'w');

            if ($out === false) {
                return;
            }

            fputcsv($out, ['id', 'created_at', 'feature', 'status', 'user', 'connection', 'provider', 'model', 'agent', 'input_tokens', 'output_tokens', 'cost_usd', 'estimated_cost_usd', 'reconciled_cost_usd', 'cost_status', 'attempts', 'latency_ms', 'error_type'], escape: '\\');

            $query->chunk(500, function ($rows) use ($out) {
                foreach ($rows as $row) {
                    $data = AiUsageReport::request($row);
                    fputcsv($out, [
                        $data['id'], $data['created_at'], $data['feature'], $data['status'], $data['user']['name'] ?? '', $data['connection'] ?? '',
                        $data['provider'] ?? '', $data['model'] ?? '', $data['agent'] ?? '', $data['input_tokens'], $data['output_tokens'],
                        $data['cost'] ?? '', $data['estimated_cost'] ?? '', $data['reconciled_cost'] ?? '', $data['cost_status'], $data['attempts'], $data['latency_ms'] ?? '', $data['error_type'] ?? '',
                    ], escape: '\\');
                }
            });

            fclose($out);
        }, 'ai-usage-'.$report->from->toDateString().'-'.$report->to->toDateString().'.csv', ['Content-Type' => 'text/csv']);
    }

    public function updateSettings(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->can('ai.budgets.manage') || $request->user()?->can('settings.update'), 403);

        $validated = $request->validate([
            'dashboard' => ['required', 'boolean'],
            'display_currency' => ['required', Rule::in(['USD', 'CLP'])],
        ]);

        settings()->setGroup('ai', $validated);
        audit('settings.updated', null, ['group' => 'ai', 'values' => $validated]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Settings saved.')]);

        return back();
    }

    /**
     * Fetch the USD/CLP rate (mindicador.cl) or store a manual one.
     */
    public function exchangeRate(Request $request, ExchangeRates $rates): RedirectResponse
    {
        abort_unless($request->user()?->can('ai.budgets.manage') || $request->user()?->can('settings.update'), 403);

        $validated = $request->validate([
            'rate' => ['nullable', 'numeric', 'min:0.0001', 'max:100000'],
            'date' => ['nullable', 'date', 'before_or_equal:today'],
        ]);

        try {
            $rate = isset($validated['rate'])
                ? $rates->store('CLP', (string) $validated['rate'], $validated['date'] ?? now()->toDateString(), 'manual')
                : $rates->fetchClp();

            audit('ai.exchange_rate.updated', null, ['quote' => 'CLP', 'rate' => (string) $rate->rate, 'source' => $rate->source]);
            Inertia::flash('toast', ['type' => 'success', 'message' => __('Exchange rate saved: :rate CLP per USD (:date).', ['rate' => (string) $rate->rate, 'date' => $rate->rate_date->toDateString()])]);
        } catch (Throwable $exception) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('The exchange rate could not be fetched: :error', ['error' => $exception->getMessage()])]);
        }

        return back();
    }

    /**
     * @return array{from?: string, to?: string, feature?: string, status?: string, cost_status?: string, user?: int|string, connection?: int|string, model?: string}
     */
    protected function filters(Request $request): array
    {
        /** @var array{from?: string, to?: string, feature?: string, status?: string, cost_status?: string, user?: int|string, connection?: int|string, model?: string} */
        return $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'feature' => ['nullable', Rule::enum(Feature::class)],
            'status' => ['nullable', Rule::enum(RequestStatus::class)],
            'cost_status' => ['nullable', Rule::enum(CostStatus::class)],
            'user' => ['nullable', 'integer'],
            'connection' => ['nullable', 'integer'],
            'model' => ['nullable', 'string', 'max:255'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<AiRequest>
     */
    protected function ledgerQuery(AiUsageReport $report, array $filters)
    {
        return $report->query()
            ->when($filters['feature'] ?? null, fn ($query, $value) => $query->where('feature', $value))
            ->when($filters['status'] ?? null, fn ($query, $value) => $query->where('status', $value))
            ->when($filters['cost_status'] ?? null, fn ($query, $value) => $query->where('cost_status', $value))
            ->when($filters['user'] ?? null, fn ($query, $value) => $query->where('user_id', (int) $value))
            ->when($filters['connection'] ?? null, fn ($query, $value) => $query->where('connection_id', (int) $value))
            ->when($filters['model'] ?? null, fn ($query, $value) => $query->where('model', $value));
    }
}
