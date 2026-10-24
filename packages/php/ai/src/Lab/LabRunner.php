<?php

namespace Starter\Ai\Lab;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Streaming\Events\TextDelta;
use Starter\Ai\Agents\LabAgent;
use Starter\Ai\AiGateway;
use Starter\Ai\Connections\ErrorClassifier;
use Starter\Ai\Enums\Feature;
use Starter\Ai\Jobs\RunLabTasks;
use Starter\Ai\Models\AiModel;
use Starter\Ai\Models\AiRequest;
use Starter\Ai\Models\LabResult;
use Starter\Ai\Models\LabRun;
use Starter\Ai\Pricing\CostCalculator;
use Starter\Ai\Pricing\CostEstimator;
use Starter\Identity\Identity;
use Throwable;

/**
 * Runs the prompts of a lab against every model. Up to max_concurrency
 * queue workers claim pending tasks (SKIP LOCKED). Before each task its
 * estimated cost is reserved atomically against the run's maximum cost;
 * tasks that do not fit, or whose price is unknown, are skipped. Every call
 * goes through the gateway (ledger feature "lab").
 */
class LabRunner
{
    public function __construct(
        protected AiGateway $gateway,
        protected CostEstimator $estimator,
    ) {}

    /**
     * @param  array{name: string, prompts: list<string>, system_prompt?: string|null, model_ids: list<int>, max_tokens?: int|null, max_cost: string|float|int, max_concurrency: int}  $attributes
     */
    public function create(array $attributes, ?Authenticatable $actor = null): LabRun
    {
        $run = DB::transaction(function () use ($attributes, $actor) {
            $run = LabRun::query()->create([
                ...$attributes,
                'max_cost' => (string) $attributes['max_cost'],
                'status' => LabRun::QUEUED,
                'created_by' => $actor?->getAuthIdentifier(),
            ]);

            foreach ($attributes['model_ids'] as $modelId) {
                foreach (array_keys($attributes['prompts']) as $index) {
                    $run->results()->create(['model_id' => $modelId, 'prompt_index' => $index, 'status' => LabResult::PENDING]);
                }
            }

            return $run;
        });

        audit('ai.lab.started', null, ['run' => $run->id, 'models' => $attributes['model_ids'], 'prompts' => count($attributes['prompts'])], $actor);

        $workers = min($run->max_concurrency, $run->results()->count());

        for ($worker = 0; $worker < $workers; $worker++) {
            RunLabTasks::dispatch($run->id);
        }

        return $run;
    }

    /**
     * Process pending tasks of the run until none is left.
     */
    public function work(LabRun $run): void
    {
        LabRun::query()->whereKey($run->id)->where('status', LabRun::QUEUED)->update(['status' => LabRun::RUNNING, 'started_at' => now()]);

        while (($result = $this->claim($run)) !== null) {
            if ($run->refresh()->status === LabRun::CANCELLED) {
                $result->forceFill(['status' => LabResult::SKIPPED, 'error' => __('Run cancelled.'), 'finished_at' => now()])->save();

                continue;
            }

            $this->execute($run, $result);
        }

        $this->finishIfDone($run);
    }

    public function cancel(LabRun $run, ?Authenticatable $actor = null): void
    {
        if (! $run->isActive()) {
            return;
        }

        $run->forceFill(['status' => LabRun::CANCELLED, 'finished_at' => now()])->save();

        LabResult::query()->where('run_id', $run->id)->where('status', LabResult::PENDING)
            ->update(['status' => LabResult::SKIPPED, 'error' => __('Run cancelled.'), 'finished_at' => now()]);

        audit('ai.lab.cancelled', null, ['run' => $run->id], $actor);
    }

    protected function claim(LabRun $run): ?LabResult
    {
        return DB::transaction(function () use ($run) {
            $result = LabResult::query()
                ->where('run_id', $run->id)
                ->where('status', LabResult::PENDING)
                ->orderBy('id')
                ->lock('for update skip locked')
                ->first();

            $result?->forceFill(['status' => LabResult::RUNNING, 'started_at' => now()])->save();

            return $result;
        });
    }

    protected function execute(LabRun $run, LabResult $result): void
    {
        $model = AiModel::query()->with('connection')->find($result->model_id);
        $prompt = (string) ($run->prompts[$result->prompt_index] ?? '');

        if ($model === null || ! AiModel::query()->usable()->whereKey($model->id)->exists()) {
            $this->finishResult($result, LabResult::SKIPPED, ['error' => __('The model is not usable.')]);

            return;
        }

        $estimate = $this->estimator->estimate($model, mb_strlen($prompt) + mb_strlen((string) $run->system_prompt), $run->max_tokens ?? 1024)->amount;

        if ($estimate === null) {
            $this->finishResult($result, LabResult::SKIPPED, ['error' => __('Unknown price: the lab budget cannot be respected.')]);

            return;
        }

        $reservation = $this->decimal($estimate);

        if (! $this->reserve($run, $reservation)) {
            $this->finishResult($result, LabResult::SKIPPED, ['error' => __('The lab budget is exhausted.'), 'estimated_cost' => $reservation]);

            return;
        }

        $userModel = Identity::userModel();
        $actor = $run->created_by !== null ? $userModel::query()->find($run->created_by) : null;
        $ledger = null;
        $started = hrtime(true);
        $ttft = null;
        $text = '';

        try {
            $stream = $this->gateway->stream(
                $model,
                Feature::Lab,
                $actor instanceof Authenticatable ? $actor : null,
                function (string $provider, string $name, AiRequest $request) use ($run, $prompt, &$ledger) {
                    $ledger = $request;

                    return (new LabAgent($run->system_prompt, $run->max_tokens))->stream($prompt, provider: $provider, model: $name);
                },
                ['input_chars' => mb_strlen($prompt), 'max_output_tokens' => $run->max_tokens, 'metadata' => ['lab_run' => $run->id]],
            );

            foreach ($stream as $event) {
                if ($event instanceof TextDelta) {
                    $ttft ??= (int) round((hrtime(true) - $started) / 1_000_000);
                    $text .= $event->delta;
                }
            }

            /** @var AiRequest $ledger */
            $ledger->refresh();
            $cost = $ledger->cost !== null ? (string) $ledger->cost : null;

            $this->settle($run, $reservation, $cost ?? $reservation);
            $this->finishResult($result, LabResult::SUCCEEDED, [
                'output' => $text,
                'latency_ms' => (int) round((hrtime(true) - $started) / 1_000_000),
                'ttft_ms' => $ttft,
                'input_tokens' => $ledger->input_tokens,
                'output_tokens' => $ledger->output_tokens,
                'estimated_cost' => $reservation,
                'cost' => $cost,
                'cost_status' => $ledger->cost_status->value,
                'ai_request_id' => $ledger->id,
            ]);
        } catch (Throwable $exception) {
            report($exception);

            $this->settle($run, $reservation, '0');
            $this->finishResult($result, LabResult::FAILED, [
                'error' => __(ErrorClassifier::classify($exception)->message()),
                'latency_ms' => (int) round((hrtime(true) - $started) / 1_000_000),
                'estimated_cost' => $reservation,
                'ai_request_id' => $ledger?->id,
            ]);
        }
    }

    /**
     * Reserve the estimate if it fits in the remaining budget (atomic).
     *
     * @param  numeric-string  $amount
     */
    protected function reserve(LabRun $run, string $amount): bool
    {
        return LabRun::query()
            ->whereKey($run->id)
            ->whereRaw('spent + reserved + CAST(? AS numeric) <= max_cost', [$amount])
            ->incrementEach(['reserved' => $amount]) === 1;
    }

    /**
     * @param  numeric-string  $reserved
     */
    protected function settle(LabRun $run, string $reserved, string $actual): void
    {
        LabRun::query()->whereKey($run->id)->incrementEach([
            'reserved' => $this->decimal('-'.$reserved),
            'spent' => $this->decimal($actual),
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function finishResult(LabResult $result, string $status, array $attributes = []): void
    {
        $result->forceFill([...$attributes, 'status' => $status, 'finished_at' => now()])->save();
    }

    protected function finishIfDone(LabRun $run): void
    {
        $open = LabResult::query()->where('run_id', $run->id)->whereIn('status', [LabResult::PENDING, LabResult::RUNNING])->exists();

        if (! $open) {
            LabRun::query()->whereKey($run->id)->where('status', LabRun::RUNNING)->update(['status' => LabRun::COMPLETED, 'finished_at' => now()]);
        }
    }

    /**
     * @return numeric-string
     */
    protected function decimal(string $value): string
    {
        $decimal = (string) BigDecimal::of(str_replace('--', '', $value))->toScale(CostCalculator::SCALE, RoundingMode::HalfUp);

        return is_numeric($decimal) ? $decimal : '0';
    }
}
