<?php

namespace App\Http\Controllers\Api\V1\Ai;

use App\Http\Controllers\Controller;
use App\Support\AiUsageReport;
use App\Support\Paginated;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Starter\Ai\Enums\Feature;
use Starter\Ai\Models\AiRequest;

/**
 * Usage ledger and cost aggregates. Requires `ai.usage.read`.
 */
#[Group('AI usage and budgets', weight: 5)]
class UsageController extends Controller
{
    /**
     * Usage summary of a date range.
     *
     * `cost` sums calculated costs in USD; requests with unknown prices are
     * counted in `unknown_cost_requests` and never added as zero.
     */
    public function summary(Request $request): JsonResponse
    {
        abort_unless($request->user()?->can('ai.usage.read'), 403);

        $validated = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);

        $report = AiUsageReport::forRange($validated['from'] ?? null, $validated['to'] ?? null);

        return response()->json([
            'range' => ['from' => $report->from->toDateString(), 'to' => $report->to->toDateString()],
            'currency' => 'USD',
            'totals' => $report->totals(),
            'daily' => $report->daily(),
            'by_feature' => $report->breakdown('feature'),
            'by_model' => $report->breakdown('model'),
            'by_connection' => $report->breakdown('connection'),
            'errors' => $report->errors(),
            'budgets' => AiUsageReport::budgets(),
        ]);
    }

    /**
     * Ledger requests (newest first).
     */
    public function requests(Request $request): JsonResponse
    {
        abort_unless($request->user()?->can('ai.usage.read'), 403);

        $validated = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'feature' => ['nullable', Rule::enum(Feature::class)],
            'user_id' => ['nullable', 'integer'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $report = AiUsageReport::forRange($validated['from'] ?? null, $validated['to'] ?? null);

        $requests = $report->query()
            ->with(['user', 'connection'])
            ->when($validated['feature'] ?? null, fn ($query, string $feature) => $query->where('feature', $feature))
            ->when($validated['user_id'] ?? null, fn ($query, int|string $id) => $query->where('user_id', (int) $id))
            ->latest()
            ->paginate($validated['per_page'] ?? 25);

        return response()->json([
            'data' => $requests->getCollection()->map(fn (AiRequest $item) => AiUsageReport::request($item))->values(),
            'meta' => Paginated::meta($requests),
        ]);
    }
}
