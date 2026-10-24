<?php

namespace App\Http\Controllers\Admin\Ai;

use App\Actions\Ai\SaveBudget;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\AiUsageReport;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Role;
use Starter\Ai\Budgets\BudgetManager;
use Starter\Ai\Enums\BudgetPeriod;
use Starter\Ai\Enums\BudgetScope;
use Starter\Ai\Enums\Feature;
use Starter\Ai\Models\Budget;
use Starter\Ai\Models\BudgetAlert;
use Starter\Ai\Models\Connection;

class BudgetController extends Controller
{
    public function index(BudgetManager $manager): Response
    {
        return Inertia::render('admin/ai/budgets/Index', [
            'budgets' => Budget::query()->orderBy('name')->get()->map(fn (Budget $budget) => [
                'id' => $budget->id,
                'name' => $budget->name,
                'scope' => $budget->scope->value,
                'scope_value' => $budget->scope_value,
                'period' => $budget->period->value,
                'limit_amount' => (string) $budget->limit_amount,
                'alert_thresholds' => $budget->thresholds(),
                'hard_limit' => $budget->hard_limit,
                'block_unknown_cost' => $budget->block_unknown_cost,
                'is_active' => $budget->is_active,
                'status' => $manager->status($budget),
            ]),
            'alerts' => BudgetAlert::query()->with('spend.budget')->latest('triggered_at')->limit(20)->get()->map(fn (BudgetAlert $alert) => [
                'id' => $alert->id,
                'budget' => $alert->spend->budget->name,
                'period' => $alert->spend->period_key,
                'threshold' => $alert->threshold,
                'spent' => (string) $alert->spent,
                'triggered_at' => $alert->triggered_at->toIso8601String(),
            ]),
            'options' => [
                'scopes' => array_map(fn (BudgetScope $scope) => $scope->value, BudgetScope::cases()),
                'periods' => array_map(fn (BudgetPeriod $period) => $period->value, BudgetPeriod::cases()),
                'features' => array_map(fn (Feature $feature) => $feature->value, Feature::cases()),
                'roles' => Role::query()->orderBy('id')->pluck('name'),
                'connections' => Connection::query()->orderBy('label')->get(['id', 'label']),
                'users' => User::query()->orderBy('name')->limit(200)->get(['id', 'name', 'email']),
            ],
            'summary' => AiUsageReport::budgets(),
        ]);
    }

    public function store(Request $request, SaveBudget $action): RedirectResponse
    {
        $action->handle($this->actor($request), $request->all());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Budget saved.')]);

        return back();
    }

    public function update(Request $request, Budget $budget, SaveBudget $action): RedirectResponse
    {
        $action->handle($this->actor($request), $request->all(), $budget);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Budget saved.')]);

        return back();
    }

    public function destroy(Request $request, Budget $budget): RedirectResponse
    {
        abort_unless($this->actor($request)->can('ai.budgets.manage'), 403);

        $name = $budget->name;
        $budget->delete();

        audit('ai.budgets.deleted', $budget, ['name' => $name]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Budget deleted.')]);

        return back();
    }

    protected function actor(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
