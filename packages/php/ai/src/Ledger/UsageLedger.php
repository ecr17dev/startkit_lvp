<?php

namespace Starter\Ai\Ledger;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Starter\Ai\Budgets\BudgetManager;
use Starter\Ai\Connections\ErrorClassifier;
use Starter\Ai\Enums\CostStatus;
use Starter\Ai\Enums\ErrorType;
use Starter\Ai\Enums\Feature;
use Starter\Ai\Enums\RequestStatus;
use Starter\Ai\Models\AiAttempt;
use Starter\Ai\Models\AiModel;
use Starter\Ai\Models\AiRequest;
use Starter\Ai\Models\CostSnapshot;
use Starter\Ai\Pricing\CostCalculator;
use Starter\Ai\Pricing\CostResult;
use Starter\Ai\Support\Usage;
use Throwable;

/**
 * Writes the usage ledger: logical requests, real attempts and immutable
 * cost snapshots. Calculated, estimated and reconciled costs are kept
 * apart and unknown costs stay null.
 */
class UsageLedger
{
    public function __construct(
        protected CostCalculator $calculator,
        protected BudgetManager $budgets,
    ) {}

    /**
     * Open a logical request. With an idempotency key an existing request is
     * returned instead of a duplicate.
     *
     * @param  array{feature: Feature, user_id?: int|null, connection_id?: int|null, model_id?: int|null, provider?: string|null, model?: string|null, agent?: string|null, agent_version_id?: int|null, conversation_id?: string|null, organization_id?: int|null, idempotency_key?: string|null, metadata?: array<string, mixed>|null}  $attributes
     */
    public function start(array $attributes, ?CostResult $estimate = null): AiRequest
    {
        if (filled($attributes['idempotency_key'] ?? null)
            && ($existing = AiRequest::query()->where('idempotency_key', $attributes['idempotency_key'])->first()) !== null) {
            return $existing;
        }

        return DB::transaction(function () use ($attributes, $estimate) {
            $request = AiRequest::query()->create([
                ...$attributes,
                'request_id' => Context::get('request_id'),
                'status' => RequestStatus::Running,
                'estimated_cost' => $estimate?->amount,
                'cost_status' => $estimate?->isKnown() ? CostStatus::Estimated : CostStatus::Pending,
                'started_at' => now(),
            ]);

            if ($estimate !== null) {
                $this->snapshot($request, null, CostSnapshot::ESTIMATE, $estimate);
            }

            return $request;
        });
    }

    /**
     * Record a real call to a provider.
     *
     * @param  array{invocation_id?: string|null, connection_id?: int|null, model?: AiModel|null, provider?: string|null, model_name?: string|null, usage?: Usage|null, latency_ms?: int|null, error?: Throwable|null, started_at?: CarbonInterface|null}  $data
     */
    public function attempt(AiRequest $request, array $data): AiAttempt
    {
        return DB::transaction(function () use ($request, $data) {
            $locked = AiRequest::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();

            $error = $data['error'] ?? null;
            $usage = $data['usage'] ?? null;
            $model = $data['model'] ?? null;
            $failed = $error !== null;
            // Failed calls without reported usage are not billed by providers...
            $billable = ! $failed || ($usage !== null && ! $usage->isEmpty());
            $cost = $billable ? $this->calculator->calculate($model, $usage ?? new Usage) : null;

            $attempt = AiAttempt::query()->create([
                'ai_request_id' => $locked->id,
                'number' => $locked->attempts_count + 1,
                'invocation_id' => $data['invocation_id'] ?? null,
                'connection_id' => $data['connection_id'] ?? $model?->connection_id,
                'model_id' => $model?->id,
                'provider' => $data['provider'] ?? null,
                'model' => $data['model_name'] ?? $model?->model,
                'status' => $failed ? 'failed' : 'succeeded',
                'billable' => $billable,
                ...($usage ?? new Usage)->columns(),
                'units' => $usage?->toArray() ?: null,
                'cost' => $cost?->amount,
                'cost_status' => $cost->status ?? CostStatus::Unknown,
                'latency_ms' => $data['latency_ms'] ?? null,
                'error_type' => $failed ? ErrorClassifier::classify($error)->value : null,
                'error_message' => $failed ? ErrorClassifier::safeMessage($error) : null,
                'started_at' => $data['started_at'] ?? null,
                'finished_at' => now(),
            ]);

            if ($cost !== null) {
                $this->snapshot($locked, $attempt, CostSnapshot::CALCULATED, $cost);
            }

            $locked->forceFill([
                'attempts_count' => $locked->attempts_count + 1,
                'model_id' => $locked->model_id ?? $model?->id,
                'model' => $locked->model ?? $attempt->model,
            ])->save();

            return $attempt;
        });
    }

    /**
     * Close the request: aggregate attempts, compute the cost and settle the
     * budget reservations. Calling it twice has no effect.
     */
    public function finish(AiRequest $request, RequestStatus $status = RequestStatus::Succeeded, ?Throwable $error = null, ?int $timeToFirstToken = null): AiRequest
    {
        return DB::transaction(function () use ($request, $status, $error, $timeToFirstToken) {
            $locked = AiRequest::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== RequestStatus::Running) {
                return $locked;
            }

            $attempts = AiAttempt::query()->where('ai_request_id', $locked->id)->where('billable', true)->get();
            [$cost, $costStatus] = $this->aggregate($attempts, $status, $locked);

            $usage = $attempts->reduce(fn (Usage $carry, AiAttempt $attempt) => $carry->add(new Usage(
                $attempt->input_tokens, $attempt->output_tokens, $attempt->cache_read_tokens, $attempt->cache_write_tokens, $attempt->reasoning_tokens,
                isset($attempt->units['audio_seconds']) ? (float) $attempt->units['audio_seconds'] : null,
                (int) ($attempt->units['images'] ?? 0),
            )), new Usage);

            $locked->forceFill([
                'status' => $status,
                ...$usage->columns(),
                'units' => $usage->toArray() ?: null,
                'cost' => $cost,
                'cost_status' => $costStatus,
                'error_type' => $error !== null ? ErrorClassifier::classify($error)->value : null,
                'error_message' => $error !== null ? ErrorClassifier::safeMessage($error) : null,
                'latency_ms' => $locked->started_at !== null ? (int) max(0, $locked->started_at->diffInMilliseconds(now())) : null,
                'time_to_first_token_ms' => $timeToFirstToken,
                'finished_at' => now(),
            ])->save();

            if ($attempts->isEmpty() && $status === RequestStatus::Failed) {
                $this->budgets->release($locked);
            } else {
                $this->budgets->settle($locked, $cost ?? ($costStatus === CostStatus::Estimated ? $locked->estimated_cost : null));
            }

            return $locked;
        });
    }

    /**
     * A budget blocked the request before any call.
     */
    public function block(AiRequest $request, Throwable $error): AiRequest
    {
        $request->forceFill([
            'status' => RequestStatus::Blocked,
            'cost' => '0',
            'cost_status' => CostStatus::Calculated,
            'error_type' => ErrorType::Budget->value,
            'error_message' => Str::limit($error->getMessage(), 500),
            'finished_at' => now(),
        ])->save();

        return $request;
    }

    /**
     * Mark a request whose stream never completed. Its usage is unknown, so
     * the estimate is kept as provisional cost and counted in the budget.
     */
    public function interrupt(AiRequest $request): AiRequest
    {
        return $this->finish($request, RequestStatus::Interrupted);
    }

    /**
     * Store the amount confirmed by the provider invoice.
     */
    public function reconcile(AiRequest $request, string $amount, ?string $notes = null): AiRequest
    {
        $amount = (string) BigDecimal::of($amount)->toScale(CostCalculator::SCALE, RoundingMode::HalfUp);

        $request->forceFill(['reconciled_cost' => $amount, 'cost_status' => CostStatus::Reconciled])->save();

        CostSnapshot::query()->create([
            'ai_request_id' => $request->id,
            'kind' => CostSnapshot::RECONCILED,
            'amount' => $amount,
            'currency' => $request->currency,
            'units' => $request->units,
            'prices' => $notes !== null ? [['notes' => $notes]] : null,
        ]);

        return $request;
    }

    /**
     * @param  Collection<int, AiAttempt>  $attempts
     * @return array{0: string|null, 1: CostStatus}
     */
    protected function aggregate($attempts, RequestStatus $status, AiRequest $request): array
    {
        if ($attempts->isEmpty()) {
            return match (true) {
                // Nothing was consumed (blocked or failed before any call)...
                $status === RequestStatus::Failed => ['0', CostStatus::Calculated],
                // The stream never finished: keep the estimate as provisional...
                $status === RequestStatus::Interrupted && $request->estimated_cost !== null => [null, CostStatus::Estimated],
                // The call ran but the usage never arrived...
                default => [null, CostStatus::Unknown],
            };
        }

        if ($attempts->contains(fn (AiAttempt $attempt) => $attempt->cost === null)) {
            return [null, CostStatus::Unknown];
        }

        $total = $attempts->reduce(fn (BigDecimal $carry, AiAttempt $attempt) => $carry->plus((string) $attempt->cost), BigDecimal::zero());

        return [(string) $total->toScale(CostCalculator::SCALE, RoundingMode::HalfUp), CostStatus::Calculated];
    }

    protected function snapshot(AiRequest $request, ?AiAttempt $attempt, string $kind, CostResult $cost): void
    {
        CostSnapshot::query()->create([
            'ai_request_id' => $request->id,
            'ai_attempt_id' => $attempt?->id,
            'kind' => $kind,
            'amount' => $cost->amount,
            'currency' => $cost->currency,
            'prices' => $cost->prices ?: null,
            'units' => $cost->units ?: null,
            'missing' => $cost->missing ?: null,
        ]);
    }
}
