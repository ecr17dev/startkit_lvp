<?php

namespace App\Actions\Ai;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Starter\Ai\Enums\BudgetPeriod;
use Starter\Ai\Enums\BudgetScope;
use Starter\Ai\Enums\Feature;
use Starter\Ai\Models\Agent;
use Starter\Ai\Models\Budget;

class SaveBudget
{
    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'scope' => ['required', Rule::enum(BudgetScope::class)],
            'scope_value' => ['nullable', 'required_unless:scope,global', 'string', 'max:120'],
            'period' => ['required', Rule::enum(BudgetPeriod::class)],
            /** Limit in USD for the period. */
            'limit_amount' => ['required', 'numeric', 'min:0', 'max:100000000'],
            'alert_thresholds' => ['nullable', 'array', 'max:6'],
            'alert_thresholds.*' => ['integer', 'between:1,1000'],
            'hard_limit' => ['boolean'],
            'block_unknown_cost' => ['boolean'],
            'is_active' => ['boolean'],
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function handle(User $actor, array $input, ?Budget $budget = null): Budget
    {
        Gate::forUser($actor)->authorize('ai.budgets.manage');

        $validator = Validator::make($input, self::rules());

        $validator->after(function ($validator) use ($input) {
            if (($input['scope'] ?? null) === BudgetScope::Feature->value && Feature::tryFrom((string) ($input['scope_value'] ?? '')) === null) {
                $validator->errors()->add('scope_value', __('Choose a valid feature.'));
            }

            if (($input['scope'] ?? null) === BudgetScope::Agent->value && ! Agent::query()->where('slug', (string) ($input['scope_value'] ?? ''))->exists()) {
                $validator->errors()->add('scope_value', __('Choose a valid agent.'));
            }
        });

        /** @var array<string, mixed> $data */
        $data = $validator->validate();

        if (($data['scope'] ?? null) === BudgetScope::Global->value) {
            $data['scope_value'] = null;
        }

        $isNew = $budget === null;
        $budget ??= new Budget(['created_by' => $actor->id]);
        $budget->fill($data)->save();

        audit($isNew ? 'ai.budgets.created' : 'ai.budgets.updated', $budget, [
            'name' => $budget->name,
            'scope' => $budget->scope->value,
            'limit' => (string) $budget->limit_amount,
            'period' => $budget->period->value,
        ], $actor);

        return $budget;
    }
}
