<?php

namespace Starter\Ai\Listeners;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Events\AgentFailed;
use Laravel\Ai\Events\AgentFailedOver;
use Laravel\Ai\Events\AgentPrompted;
use Laravel\Ai\Events\AgentStreamed;
use Laravel\Ai\Events\EmbeddingsGenerated;
use Laravel\Ai\Events\PromptingAgent;
use Laravel\Ai\Events\StreamingAgent;
use Laravel\Ai\Events\TranscriptionGenerated;
use Starter\Ai\Connections\ConnectionResolver;
use Starter\Ai\Enums\Feature;
use Starter\Ai\Enums\RequestStatus;
use Starter\Ai\Ledger\ExecutionContext;
use Starter\Ai\Ledger\UsageLedger;
use Starter\Ai\Models\AiModel;
use Starter\Ai\Models\AiRequest;
use Starter\Ai\Support\Usage;
use Throwable;

/**
 * Records every SDK call in the ledger. Calls made through AiGateway are
 * attached to their request; any other call (packages, custom code) gets
 * an implicit request so nothing escapes the ledger.
 */
class RecordSdkUsage
{
    public function __construct(
        protected ExecutionContext $context,
        protected UsageLedger $ledger,
    ) {}

    public function subscribe(Dispatcher $events): void
    {
        // Laravel only resolves listeners by exact class and interfaces, so
        // the streaming subclasses are registered explicitly...
        $events->listen([PromptingAgent::class, StreamingAgent::class], [self::class, 'starting']);
        $events->listen([AgentPrompted::class, AgentStreamed::class], [self::class, 'prompted']);
        $events->listen(AgentFailedOver::class, [self::class, 'failedOver']);
        $events->listen(AgentFailed::class, [self::class, 'failed']);
        $events->listen(EmbeddingsGenerated::class, [self::class, 'embeddings']);
        $events->listen(TranscriptionGenerated::class, [self::class, 'transcription']);
    }

    public function starting(PromptingAgent $event): void
    {
        $this->context->bind($event->invocationId);
    }

    public function prompted(AgentPrompted $event): void
    {
        $this->safely(function () use ($event) {
            $providerName = $event->prompt->provider->name();
            $modelName = $event->response->meta->model ?? $event->prompt->model;
            [$request, $implicit] = $this->requestFor($event->invocationId, $providerName, (string) $modelName, $this->featureFor($event->prompt->agent::class));

            $this->ledger->attempt($request, [
                'invocation_id' => $event->invocationId,
                'connection_id' => ConnectionResolver::connectionId($providerName),
                'model' => $this->catalogModel($providerName, (string) $modelName),
                'provider' => $event->response->meta->provider ?? $providerName,
                'model_name' => (string) $modelName,
                'usage' => Usage::fromSdk($event->response->usage),
                'latency_ms' => $this->context->elapsed($event->invocationId),
            ]);

            // Streams (and implicit requests) end with the event...
            if ($implicit || $event instanceof AgentStreamed) {
                $this->ledger->finish($request);
            }

            $this->context->forget($event->invocationId);
        });
    }

    public function failedOver(AgentFailedOver $event): void
    {
        $this->safely(function () use ($event) {
            $providerName = $event->provider->name();
            [$request] = $this->requestFor($event->invocationId, $providerName, $event->model, $this->featureFor($event->agent::class));

            $this->ledger->attempt($request, [
                'invocation_id' => $event->invocationId,
                'connection_id' => ConnectionResolver::connectionId($providerName),
                'model' => $this->catalogModel($providerName, $event->model),
                'provider' => $providerName,
                'model_name' => $event->model,
                'error' => $event->exception,
                'latency_ms' => $this->context->elapsed($event->invocationId),
            ]);
        });
    }

    public function failed(AgentFailed $event): void
    {
        $this->safely(function () use ($event) {
            $providerName = $event->prompt->provider->name();
            [$request, $implicit] = $this->requestFor($event->invocationId, $providerName, $event->prompt->model, $this->featureFor($event->prompt->agent::class));

            $this->ledger->attempt($request, [
                'invocation_id' => $event->invocationId,
                'connection_id' => ConnectionResolver::connectionId($providerName),
                'model' => $this->catalogModel($providerName, $event->prompt->model),
                'provider' => $providerName,
                'model_name' => $event->prompt->model,
                'error' => $event->exception,
                'latency_ms' => $this->context->elapsed($event->invocationId),
            ]);

            // A failed stream never returns to the gateway...
            if ($implicit || $this->context->current()?->id !== $request->id) {
                $this->ledger->finish($request, RequestStatus::Failed, $event->exception);
            }

            $this->context->forget($event->invocationId);
        });
    }

    public function embeddings(EmbeddingsGenerated $event): void
    {
        $this->recordGeneric($event->invocationId, $event->provider->name(), $event->model, Feature::Embedding, Usage::fromSdk($event->response->usage));
    }

    public function transcription(TranscriptionGenerated $event): void
    {
        $this->recordGeneric($event->invocationId, $event->provider->name(), $event->model, Feature::Transcription, Usage::fromSdk($event->response->usage));
    }

    protected function recordGeneric(string $invocationId, string $providerName, string $model, Feature $feature, Usage $usage): void
    {
        $this->safely(function () use ($invocationId, $providerName, $model, $feature, $usage) {
            $current = $this->context->current();
            [$request, $implicit] = $current !== null ? [$current, false] : $this->requestFor($invocationId, $providerName, $model, $feature);

            $this->ledger->attempt($request, [
                'invocation_id' => $invocationId,
                'connection_id' => ConnectionResolver::connectionId($providerName),
                'model' => $this->catalogModel($providerName, $model),
                'provider' => $providerName,
                'model_name' => $model,
                'usage' => $usage,
            ]);

            if ($implicit) {
                $this->ledger->finish($request);
            }
        });
    }

    /**
     * @return array{0: AiRequest, 1: bool} the request and whether it was created here
     */
    protected function requestFor(string $invocationId, string $providerName, string $model, Feature $feature): array
    {
        $request = $this->context->requestFor($invocationId);

        if ($request !== null) {
            return [$request, false];
        }

        $catalog = $this->catalogModel($providerName, $model);

        return [$this->ledger->start([
            'feature' => $feature,
            'user_id' => is_numeric(Auth::id()) ? (int) Auth::id() : null,
            'connection_id' => ConnectionResolver::connectionId($providerName),
            'model_id' => $catalog?->id,
            'provider' => $catalog?->connection->driver->value ?? $providerName,
            'model' => $model,
            'idempotency_key' => 'sdk:'.$invocationId.':'.$feature->value,
        ]), true];
    }

    protected function catalogModel(string $providerName, string $model): ?AiModel
    {
        $connectionId = ConnectionResolver::connectionId($providerName);

        if ($connectionId === null || $model === '') {
            return null;
        }

        return AiModel::query()->with('connection')->where('connection_id', $connectionId)->where('model', $model)->first();
    }

    /**
     * @param  class-string  $agent
     */
    protected function featureFor(string $agent): Feature
    {
        $feature = config('starter.ai.agent_features.'.$agent);

        return is_string($feature) ? (Feature::tryFrom($feature) ?? Feature::Other) : Feature::Other;
    }

    /**
     * Never break an AI response because the usage could not be recorded.
     */
    protected function safely(callable $callback): void
    {
        try {
            $callback();
        } catch (Throwable $exception) {
            Log::error('Unable to record AI usage: '.$exception->getMessage(), ['exception' => $exception]);
        }
    }
}
