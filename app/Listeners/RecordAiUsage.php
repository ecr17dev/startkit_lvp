<?php

namespace App\Listeners;

use App\Ai\ProviderRegistry;
use App\Ai\Usage;
use App\Ai\UsageContext;
use App\Models\AiUsageLog;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Events\AgentPrompted;
use Laravel\Ai\Events\AgentStreamed;
use Throwable;

/**
 * Store the tokens and cost of every agent call made through the SDK.
 */
class RecordAiUsage
{
    public function __construct(protected UsageContext $context) {}

    /**
     * Handle the event.
     */
    public function handle(AgentPrompted|AgentStreamed $event): void
    {
        try {
            $this->record($event);
        } catch (Throwable $e) {
            // Never break the AI response because the usage could not be logged...
            Log::warning('Unable to record AI usage: '.$e->getMessage(), ['invocation_id' => $event->invocationId]);
        }
    }

    protected function record(AgentPrompted $event): void
    {
        $response = $event->response;
        $model = $response->meta->model ?? $event->prompt->model;
        $usage = Usage::fromSdk($response->usage);

        $pricing = ProviderRegistry::modelFor($event->prompt->provider->name(), $event->prompt->model)
            ?? ProviderRegistry::modelFor($event->prompt->provider->name(), (string) $model);

        $user = $this->context->user ?? Auth::user();

        AiUsageLog::query()->create([
            'user_id' => $user instanceof User ? $user->getKey() : null,
            'ai_prompt_id' => $this->context->prompt?->getKey(),
            'invocation_id' => $event->invocationId,
            'conversation_id' => $response->conversationId,
            'agent' => $event->prompt->agent::class,
            'source' => $this->context->source,
            'provider' => $event->prompt->provider->name(),
            'model' => (string) $model,
            'input_tokens' => $usage->inputTokens,
            'output_tokens' => $usage->outputTokens,
            'cache_read_tokens' => $usage->cacheReadTokens,
            'cache_write_tokens' => $usage->cacheWriteTokens,
            'reasoning_tokens' => $usage->reasoningTokens,
            'cost' => $pricing?->isPriced() ? $pricing->costFor($usage) : 0,
            'priced' => (bool) $pricing?->isPriced(),
        ]);
    }
}
