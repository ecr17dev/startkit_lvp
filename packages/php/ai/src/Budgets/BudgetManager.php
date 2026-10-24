<?php

namespace Starter\Ai\Budgets;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Starter\Ai\Enums\BudgetScope;
use Starter\Ai\Enums\Feature;
use Starter\Ai\Exceptions\BudgetExceededException;
use Starter\Ai\Models\AiRequest;
use Starter\Ai\Models\Budget;
use Starter\Ai\Models\BudgetAlert;
use Starter\Ai\Models\BudgetReservation;
use Starter\Ai\Models\BudgetSpend;
use Starter\Ai\Notifications\BudgetThresholdReached;
use Starter\Identity\Identity;

/**
 * Atomic budget reservations.
 *
 * Before a call the estimated cost is reserved with a conditional UPDATE
 * (spent + reserved + amount <= limit), so concurrent calls cannot exceed a
 * hard limit by more than the difference between estimates and final
 * costs. When the call ends the reservation is replaced by the real cost.
 */
class BudgetManager
{
    public const RESERVATION_TTL_MINUTES = 60;

    /**
     * Budgets that apply to a call.
     *
     * @return Collection<int, Budget>
     */
    public function applicable(?Authenticatable $user, Feature $feature, ?int $connectionId): Collection
    {
        $roles = $user !== null && method_exists($user, 'getRoleNames') ? $user->getRoleNames()->all() : [];

        return Budget::query()
            ->active()
            ->get()
            ->filter(fn (Budget $budget) => match ($budget->scope) {
                BudgetScope::Global => true,
                BudgetScope::User => $user !== null && (string) $user->getAuthIdentifier() === $budget->scope_value,
                BudgetScope::Role => in_array($budget->scope_value, $roles, true),
                BudgetScope::Feature => $budget->scope_value === $feature->value,
                BudgetScope::Connection => $connectionId !== null && (string) $connectionId === $budget->scope_value,
            })
            ->values();
    }

    /**
     * Reserve the estimated cost in every applicable budget, or fail without
     * reserving anything.
     *
     * @throws BudgetExceededException
     */
    public function reserve(AiRequest $request, ?string $estimate, ?Authenticatable $user): void
    {
        $budgets = $this->applicable($user, $request->feature, $request->connection_id);

        if ($budgets->isEmpty()) {
            return;
        }

        $amount = $estimate !== null ? (string) BigDecimal::of($estimate) : '0';

        DB::transaction(function () use ($budgets, $request, $estimate, $amount) {
            foreach ($budgets as $budget) {
                if ($estimate === null && $budget->hard_limit && $budget->block_unknown_cost) {
                    throw new BudgetExceededException($budget, (string) __('The AI budget ":name" does not allow calls with unknown cost.', ['name' => $budget->name]));
                }

                $spend = $this->spendFor($budget);

                $query = BudgetSpend::query()->whereKey($spend->id);

                if ($budget->hard_limit) {
                    $query->whereRaw('spent + reserved + CAST(? AS numeric) <= CAST(? AS numeric)', [$amount, (string) $budget->limit_amount]);
                }

                $updated = $query->incrementEach(['reserved' => $this->sqlDecimal($amount)], ['updated_at' => now()]);

                if ($updated === 0) {
                    throw new BudgetExceededException($budget);
                }

                BudgetReservation::query()->create([
                    'budget_period_id' => $spend->id,
                    'ai_request_id' => $request->id,
                    'amount' => $amount,
                    'status' => BudgetReservation::RESERVED,
                    'expires_at' => now()->addMinutes(self::RESERVATION_TTL_MINUTES),
                ]);
            }
        });
    }

    /**
     * Replace the reservations of the request by its final cost (null when
     * unknown: counted as unknown cost request, the amount cannot be added).
     */
    public function settle(AiRequest $request, ?string $actual): void
    {
        $this->close($request, BudgetReservation::SETTLED, $actual);
        $this->chargeUnreserved($request, $actual);
    }

    /**
     * Give back the reservations of a request that consumed nothing.
     */
    public function release(AiRequest $request): void
    {
        $this->close($request, BudgetReservation::RELEASED, null);
    }

    /**
     * Release reservations whose request never finished in time.
     */
    public function releaseExpired(): int
    {
        $count = 0;

        BudgetReservation::query()
            ->where('status', BudgetReservation::RESERVED)
            ->where('expires_at', '<', now())
            ->with('spend')
            ->chunkById(100, function (Collection $reservations) use (&$count) {
                foreach ($reservations as $reservation) {
                    $count += $this->closeReservation($reservation, BudgetReservation::RELEASED, null) ? 1 : 0;
                }
            });

        return $count;
    }

    /**
     * Current status of a budget.
     *
     * @return array{period_key: string, spent: string, reserved: string, limit: string, percent: float, unknown_cost_requests: int}
     */
    public function status(Budget $budget): array
    {
        $spend = BudgetSpend::query()
            ->where('budget_id', $budget->id)
            ->where('period_key', $budget->period->key(now()))
            ->first();

        $spent = (string) ($spend->spent ?? '0');
        $limit = (string) $budget->limit_amount;

        return [
            'period_key' => $budget->period->key(now()),
            'spent' => $spent,
            'reserved' => (string) ($spend->reserved ?? '0'),
            'limit' => $limit,
            'percent' => BigDecimal::of($limit)->isZero() ? 0.0 : BigDecimal::of($spent)->multipliedBy(100)->dividedBy($limit, 2, RoundingMode::HalfUp)->toFloat(),
            'unknown_cost_requests' => (int) ($spend->unknown_cost_requests ?? 0),
        ];
    }

    protected function close(AiRequest $request, string $status, ?string $actual): void
    {
        BudgetReservation::query()
            ->where('ai_request_id', $request->id)
            ->where('status', BudgetReservation::RESERVED)
            ->with('spend.budget')
            ->get()
            ->each(fn (BudgetReservation $reservation) => $this->closeReservation($reservation, $status, $actual));
    }

    protected function closeReservation(BudgetReservation $reservation, string $status, ?string $actual): bool
    {
        $closed = DB::transaction(function () use ($reservation, $status, $actual) {
            $affected = BudgetReservation::query()
                ->whereKey($reservation->id)
                ->where('status', BudgetReservation::RESERVED)
                ->update([
                    'status' => $status,
                    'settled_amount' => $status === BudgetReservation::SETTLED ? $actual : null,
                    'updated_at' => now(),
                ]);

            if ($affected === 0) {
                return false;
            }

            BudgetSpend::query()->whereKey($reservation->budget_period_id)->incrementEach([
                'reserved' => $this->sqlDecimal('-'.$reservation->amount),
                'spent' => $status === BudgetReservation::SETTLED && $actual !== null ? $this->sqlDecimal($actual) : '0',
                'unknown_cost_requests' => $status === BudgetReservation::SETTLED && $actual === null ? 1 : 0,
            ], ['updated_at' => now()]);

            return true;
        });

        if ($closed && $status === BudgetReservation::SETTLED) {
            $this->checkAlerts(BudgetSpend::query()->with('budget')->findOrFail($reservation->budget_period_id));
        }

        return $closed;
    }

    /**
     * Record and notify every threshold crossed in the period (once each).
     */
    public function checkAlerts(BudgetSpend $spend): void
    {
        $budget = $spend->budget;
        $limit = BigDecimal::of((string) $budget->limit_amount);

        if ($limit->isZero()) {
            return;
        }

        $percent = BigDecimal::of((string) $spend->spent)->multipliedBy(100)->dividedBy($limit, 2, RoundingMode::Down);

        foreach ($budget->thresholds() as $threshold) {
            if ($percent->isLessThan($threshold)) {
                continue;
            }

            $created = BudgetAlert::query()->insertOrIgnore([
                'budget_period_id' => $spend->id,
                'threshold' => $threshold,
                'spent' => (string) $spend->spent,
                'triggered_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            if ($created > 0) {
                $this->notify($budget, $spend, $threshold);
            }
        }
    }

    protected function notify(Budget $budget, BudgetSpend $spend, int $threshold): void
    {
        audit('ai.budget.threshold', $budget, ['threshold' => $threshold, 'spent' => (string) $spend->spent, 'period' => $spend->period_key]);

        $recipients = Identity::activeUsersWithPermission('ai.budgets.manage');

        if ($recipients->isNotEmpty()) {
            Notification::send($recipients, new BudgetThresholdReached($budget, $spend, $threshold));
        }
    }

    /**
     * Add the cost to the applicable budgets that hold no reservation for the
     * request. Direct SDK calls are only observed through events, so they
     * cannot be blocked beforehand, but their cost is never left out.
     */
    protected function chargeUnreserved(AiRequest $request, ?string $actual): void
    {
        $user = $request->user;
        $budgets = $this->applicable($user instanceof Authenticatable ? $user : null, $request->feature, $request->connection_id);

        if ($budgets->isEmpty()) {
            return;
        }

        $reserved = BudgetSpend::query()
            ->whereIn('id', BudgetReservation::query()->where('ai_request_id', $request->id)->select('budget_period_id'))
            ->pluck('budget_id')
            ->all();

        foreach ($budgets->reject(fn (Budget $budget) => in_array($budget->id, $reserved, true)) as $budget) {
            $spend = $this->spendFor($budget);

            BudgetSpend::query()->whereKey($spend->id)->incrementEach([
                'spent' => $actual !== null ? $this->sqlDecimal($actual) : '0',
                'unknown_cost_requests' => $actual === null ? 1 : 0,
            ], ['updated_at' => now()]);

            $this->checkAlerts(BudgetSpend::query()->with('budget')->findOrFail($spend->id));
        }
    }

    protected function spendFor(Budget $budget): BudgetSpend
    {
        return BudgetSpend::query()->createOrFirst([
            'budget_id' => $budget->id,
            'period_key' => $budget->period->key(now()),
        ]);
    }

    /**
     * Normalized decimal string for SQL increments.
     *
     * @return numeric-string
     */
    protected function sqlDecimal(string $value): string
    {
        $decimal = (string) BigDecimal::of(str_replace('--', '', $value))->toScale(10, RoundingMode::HalfUp);

        return is_numeric($decimal) ? $decimal : '0';
    }
}
