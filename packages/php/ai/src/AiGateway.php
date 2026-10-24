<?php

namespace Starter\Ai;

use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Starter\Ai\Budgets\BudgetManager;
use Starter\Ai\Connections\ConnectionResolver;
use Starter\Ai\Enums\Feature;
use Starter\Ai\Enums\RequestStatus;
use Starter\Ai\Exceptions\BudgetExceededException;
use Starter\Ai\Exceptions\ModelNotUsableException;
use Starter\Ai\Ledger\ExecutionContext;
use Starter\Ai\Ledger\UsageLedger;
use Starter\Ai\Models\AiModel;
use Starter\Ai\Models\AiRequest;
use Starter\Ai\Pricing\CostEstimator;
use Throwable;

/**
 * Single entry point for AI calls made by the starter: resolves the
 * connection on demand, estimates the cost, reserves budget, opens the
 * ledger request and lets the SDK listeners record the real attempts.
 *
 *     $gateway->run($model, Feature::Chat, $user,
 *         fn (string $provider, string $model) => $agent->prompt($text, provider: $provider, model: $model),
 *         ['input_chars' => strlen($text), 'max_output_tokens' => 1200]);
 */
class AiGateway
{
    public function __construct(
        protected ConnectionResolver $resolver,
        protected CostEstimator $estimator,
        protected UsageLedger $ledger,
        protected BudgetManager $budgets,
        protected ExecutionContext $context,
    ) {}

    /**
     * Run a call and close its ledger request when the callback returns.
     *
     * @template TResult
     *
     * @param  Closure(string $provider, string $model, AiRequest $request): TResult  $callback
     * @param  array{input_chars?: int, max_output_tokens?: int|null, agent?: string|null, agent_version_id?: int|null, conversation_id?: string|null, idempotency_key?: string|null, metadata?: array<string, mixed>}  $options
     * @return TResult
     */
    public function run(AiModel $model, Feature $feature, ?Authenticatable $user, Closure $callback, array $options = []): mixed
    {
        $request = $this->open($model, $feature, $user, $options);
        $provider = $this->resolver->providerName($model->connection);

        $this->context->push($request);

        try {
            $result = $callback($provider, $model->model, $request);
        } catch (Throwable $exception) {
            $this->ledger->finish($request, RequestStatus::Failed, $exception);

            throw $exception;
        } finally {
            $this->context->pop($request);
        }

        $this->ledger->finish($request);

        return $result;
    }

    /**
     * Start a streamed call. The request is closed by the SDK listener when
     * the stream completes (or marked interrupted by the scheduler).
     *
     * @template TResult
     *
     * @param  Closure(string $provider, string $model, AiRequest $request): TResult  $callback
     * @param  array{input_chars?: int, max_output_tokens?: int|null, agent?: string|null, agent_version_id?: int|null, conversation_id?: string|null, idempotency_key?: string|null, metadata?: array<string, mixed>}  $options
     * @return TResult
     */
    public function stream(AiModel $model, Feature $feature, ?Authenticatable $user, Closure $callback, array $options = []): mixed
    {
        $request = $this->open($model, $feature, $user, $options);
        $provider = $this->resolver->providerName($model->connection);

        try {
            $result = $callback($provider, $model->model, $request);
        } catch (Throwable $exception) {
            $this->ledger->finish($request, RequestStatus::Failed, $exception);

            throw $exception;
        }

        $this->context->await($request);

        return $result;
    }

    /**
     * @param  array{input_chars?: int, max_output_tokens?: int|null, agent?: string|null, agent_version_id?: int|null, conversation_id?: string|null, idempotency_key?: string|null, metadata?: array<string, mixed>}  $options
     */
    protected function open(AiModel $model, Feature $feature, ?Authenticatable $user, array $options): AiRequest
    {
        $connection = $model->connection;

        if (! $model->is_enabled || ! $connection->is_active) {
            throw ModelNotUsableException::none();
        }

        $estimate = $this->estimator->estimate($model, (int) ($options['input_chars'] ?? 0), $options['max_output_tokens'] ?? null);

        $request = $this->ledger->start([
            'feature' => $feature,
            'user_id' => $user?->getAuthIdentifier(),
            'connection_id' => $connection->id,
            'model_id' => $model->id,
            'provider' => $connection->driver->value,
            'model' => $model->model,
            'agent' => $options['agent'] ?? null,
            'agent_version_id' => $options['agent_version_id'] ?? null,
            'conversation_id' => $options['conversation_id'] ?? null,
            'idempotency_key' => $options['idempotency_key'] ?? null,
            'metadata' => $options['metadata'] ?? null,
        ], $estimate);

        try {
            $this->budgets->reserve($request, $estimate->amount, $user);
        } catch (BudgetExceededException $exception) {
            $this->ledger->block($request, $exception);

            throw $exception;
        }

        return $request;
    }
}
