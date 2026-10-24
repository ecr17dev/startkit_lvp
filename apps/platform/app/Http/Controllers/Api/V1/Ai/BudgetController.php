<?php

namespace App\Http\Controllers\Api\V1\Ai;

use App\Actions\Ai\SaveBudget;
use App\Http\Controllers\Controller;
use App\Models\User;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Starter\Ai\Budgets\BudgetManager;
use Starter\Ai\Models\Budget;

/**
 * Budgets by period and scope. Requires `ai.budgets.manage`.
 */
#[Group('AI usage and budgets', weight: 5)]
class BudgetController extends Controller
{
    /**
     * List budgets with the status of their current period.
     */
    public function index(Request $request, BudgetManager $manager): JsonResponse
    {
        abort_unless($this->actor($request)->can('ai.budgets.manage'), 403);

        return response()->json([
            'data' => Budget::query()->orderBy('name')->get()->map(fn (Budget $budget) => $this->present($budget, $manager))->values(),
        ]);
    }

    /**
     * Create a budget.
     *
     * Hard limits block calls whose estimated cost would exceed the limit
     * (atomic reservation); soft limits only alert.
     */
    public function store(Request $request, SaveBudget $action, BudgetManager $manager): JsonResponse
    {
        $request->validate(SaveBudget::rules());

        return response()->json(['data' => $this->present($action->handle($this->actor($request), $request->all()), $manager)], 201);
    }

    /**
     * Update a budget.
     */
    public function update(Request $request, Budget $budget, SaveBudget $action, BudgetManager $manager): JsonResponse
    {
        $request->validate(SaveBudget::rules());

        return response()->json(['data' => $this->present($action->handle($this->actor($request), $request->all(), $budget), $manager)]);
    }

    /**
     * Delete a budget.
     */
    public function destroy(Request $request, Budget $budget): JsonResponse
    {
        abort_unless($this->actor($request)->can('ai.budgets.manage'), 403);

        $budget->delete();
        audit('ai.budgets.deleted', $budget, ['name' => $budget->name]);

        return response()->json(['message' => __('Budget deleted.')]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function present(Budget $budget, BudgetManager $manager): array
    {
        return [
            'id' => $budget->id,
            'name' => $budget->name,
            'scope' => $budget->scope->value,
            'scope_value' => $budget->scope_value,
            'period' => $budget->period->value,
            'limit_amount' => (string) $budget->limit_amount,
            'currency' => $budget->currency,
            'alert_thresholds' => $budget->thresholds(),
            'hard_limit' => $budget->hard_limit,
            'block_unknown_cost' => $budget->block_unknown_cost,
            'is_active' => $budget->is_active,
            'status' => $manager->status($budget),
        ];
    }

    protected function actor(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
