<?php

namespace App\Ai;

use App\Ai\Agents\Assistant;
use App\Ai\Exceptions\BudgetExceededException;
use App\Models\AiPrompt;
use App\Models\AiProvider;
use App\Models\AiUsageLog;
use App\Models\User;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\StreamableAgentResponse;
use Starter\Administration\Settings\SettingsManager;

class AiService
{
    public function __construct(
        protected SettingsManager $settings,
        protected UsageContext $context,
    ) {}

    /**
     * Send a message to the assistant and wait for the full response.
     */
    public function chat(
        User $user,
        string $message,
        ?AiPrompt $prompt = null,
        ?string $provider = null,
        ?string $model = null,
        ?string $conversationId = null,
        string $source = 'web',
    ): AgentResponse {
        $prompt ??= AiPrompt::default();
        [$provider, $model] = $this->resolveTarget($prompt, $provider, $model);

        $this->ensureWithinBudget($user);

        return $this->context->run($user, $prompt, $source, fn () => $this->assistant($user, $prompt, $conversationId)
            ->prompt($message, provider: $provider, model: $model));
    }

    /**
     * Send a message to the assistant and stream the response.
     */
    public function stream(
        User $user,
        string $message,
        ?AiPrompt $prompt = null,
        ?string $provider = null,
        ?string $model = null,
        ?string $conversationId = null,
        string $source = 'web',
    ): StreamableAgentResponse {
        $prompt ??= AiPrompt::default();
        [$provider, $model] = $this->resolveTarget($prompt, $provider, $model);

        $this->ensureWithinBudget($user);

        // The stream is consumed after this method returns, so the context
        // stays set for the remainder of the request...
        $this->context->set($user, $prompt, $source);

        return $this->assistant($user, $prompt, $conversationId)
            ->stream($message, provider: $provider, model: $model);
    }

    /**
     * Build the assistant agent for the given user and conversation.
     */
    public function assistant(User $user, ?AiPrompt $prompt, ?string $conversationId = null): Assistant
    {
        $assistant = new Assistant($prompt, [
            'app_name' => (string) $this->settings->get('branding.app_name', config('app.name')),
            'user_name' => $user->name,
            'user_email' => $user->email,
            'locale' => app()->getLocale(),
            'date' => Carbon::now()->toDateString(),
            'datetime' => Carbon::now()->toDateTimeString(),
        ]);

        return $assistant->continueOrStart($conversationId, $user);
    }

    /**
     * Resolve the provider and model to use for the call.
     *
     * Priority: explicit arguments, then the system prompt, then the default provider.
     *
     * @return array{0: string, 1: string|null}
     */
    public function resolveTarget(?AiPrompt $prompt, ?string $provider, ?string $model): array
    {
        if ($provider === null && $prompt?->provider?->is_active) {
            $provider = $prompt->provider->name;
            $model ??= $prompt->model;
        }

        $provider ??= ProviderRegistry::defaults()['provider'];

        if ($provider === null || config("ai.providers.{$provider}") === null) {
            throw new InvalidArgumentException(__('No AI provider is configured. Add one from Admin → AI → Providers.'));
        }

        if ($model === null) {
            $model = AiProvider::query()->where('name', $provider)->value('default_model');
        }

        return [$provider, $model];
    }

    /**
     * Ensure the global and per user monthly budgets have not been exceeded.
     *
     * @throws BudgetExceededException
     */
    public function ensureWithinBudget(User $user): void
    {
        if (! $this->settings->get('ai.enforce_budget')) {
            return;
        }

        $globalBudget = $this->settings->get('ai.monthly_budget');

        if (is_numeric($globalBudget) && $this->monthlySpend() >= (float) $globalBudget) {
            throw new BudgetExceededException(__('The monthly AI budget has been reached.'));
        }

        $userBudget = $this->settings->get('ai.user_monthly_budget');

        if (is_numeric($userBudget) && $this->monthlySpend($user) >= (float) $userBudget) {
            throw new BudgetExceededException(__('You have reached your monthly AI budget.'));
        }
    }

    /**
     * Get the spend of the current month, optionally for a single user.
     */
    public function monthlySpend(?User $user = null): float
    {
        return (float) AiUsageLog::query()
            ->where('created_at', '>=', Carbon::now()->startOfMonth())
            ->when($user, fn ($query) => $query->where('user_id', $user->getKey()))
            ->sum('cost');
    }
}
