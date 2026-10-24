<?php

namespace App\Ai;

use App\Models\User;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Closure;
use Illuminate\Support\Carbon;
use Laravel\Ai\Approvals\Decision;
use Laravel\Ai\Approvals\Decisions;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Files\File;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\StreamableAgentResponse;
use Starter\Administration\Settings\SettingsManager;
use Starter\Ai\Agents\AgentLimiter;
use Starter\Ai\Agents\AgentManager;
use Starter\Ai\Agents\ConfiguredAgent;
use Starter\Ai\Agents\ToolContext;
use Starter\Ai\Agents\ToolKind;
use Starter\Ai\Agents\ToolRegistry;
use Starter\Ai\AiGateway;
use Starter\Ai\Enums\Feature;
use Starter\Ai\Exceptions\ModelNotUsableException;
use Starter\Ai\Models\ActionProposal;
use Starter\Ai\Models\AgentVersion;
use Starter\Ai\Models\AiModel;
use Starter\Ai\Models\AiRequest;
use Starter\Ai\Pricing\CostCalculator;
use Throwable;

/**
 * Conversations with the configured agents. The server resolves the
 * published version, its model, fallbacks, tools and limits; every call
 * goes through the AI gateway (estimate, budget reservation, ledger).
 */
class AiService
{
    public function __construct(
        protected SettingsManager $settings,
        protected AiGateway $gateway,
        protected AgentManager $agents,
        protected AgentLimiter $limiter,
        protected ToolRegistry $tools,
    ) {}

    /**
     * Send a message (or approval decisions) and wait for the full response.
     *
     * @param  list<File>  $attachments
     * @return array{response: AgentResponse, request: AiRequest}
     */
    public function chat(User $user, AgentVersion $version, string|Decisions $input, array $attachments = [], ?AiModel $model = null, ?string $conversationId = null, string $surface = 'api'): array
    {
        $release = $this->limiter->acquire($version, $user);

        try {
            $model = $this->resolveModel($version, $model);
            $context = new ToolContext($user, $version, $surface, $conversationId);
            $agent = $this->agent($user, $version, $conversationId, $surface, $context);
            $providers = $this->providers($version, $model);
            $ledger = null;

            $response = $this->gateway->run(
                $model,
                Feature::Chat,
                $user,
                function (string $provider, string $name, AiRequest $request) use ($agent, $context, $input, $attachments, $providers, &$ledger) {
                    $ledger = $request;
                    $context->requestId = $request->id;

                    return $agent->prompt($input, $attachments, provider: $providers);
                },
                $this->options($version, $input, $conversationId),
            );
        } finally {
            $release();
        }

        /** @var AiRequest $ledger */
        return ['response' => $response, 'request' => $ledger->refresh()];
    }

    /**
     * Send a message (or approval decisions) and stream the response. The
     * caller must invoke the returned release callback when the stream ends.
     *
     * @param  list<File>  $attachments
     * @return array{response: StreamableAgentResponse, request: AiRequest, release: Closure(): void}
     */
    public function stream(User $user, AgentVersion $version, string|Decisions $input, array $attachments = [], ?AiModel $model = null, ?string $conversationId = null, string $surface = 'page'): array
    {
        $release = $this->limiter->acquire($version, $user);

        try {
            $model = $this->resolveModel($version, $model);
            $context = new ToolContext($user, $version, $surface, $conversationId);
            $agent = $this->agent($user, $version, $conversationId, $surface, $context);
            $providers = $this->providers($version, $model);
            $ledger = null;

            $response = $this->gateway->stream(
                $model,
                Feature::Chat,
                $user,
                function (string $provider, string $name, AiRequest $request) use ($agent, $context, $input, $attachments, $providers, &$ledger) {
                    $ledger = $request;
                    $context->requestId = $request->id;

                    return $agent->stream($input, $attachments, provider: $providers);
                },
                $this->options($version, $input, $conversationId),
            );
        } catch (Throwable $exception) {
            $release();

            throw $exception;
        }

        /** @var AiRequest $ledger */
        return ['response' => $response, 'request' => $ledger, 'release' => $release];
    }

    /**
     * Try a version (usually the draft) from the panel. The call is recorded
     * as a test, is not remembered as a conversation and only offers the
     * read-only tools, so a test never changes data.
     *
     * @return array{response: AgentResponse, request: AiRequest, tools: list<string>}
     */
    public function test(User $actor, AgentVersion $version, string $message): array
    {
        $model = $this->resolveModel($version);
        $context = new ToolContext($actor, $version, 'page');
        $tools = array_values(array_filter(
            $this->tools->resolve($version->settings()->tools(), $context),
            fn (Tool $tool) => $this->tools->get($this->toolName($tool))?->kind === ToolKind::Read,
        ));
        $agent = new ConfiguredAgent($version, $this->variables($actor), $tools);
        $providers = $this->providers($version, $model);
        $ledger = null;

        $response = $this->gateway->run(
            $model,
            Feature::Test,
            $actor,
            function (string $provider, string $name, AiRequest $request) use ($agent, $message, $providers, &$ledger) {
                $ledger = $request;

                return $agent->prompt($message, provider: $providers);
            },
            [...$this->options($version, $message, null), 'metadata' => ['test' => true, 'version' => $version->number]],
        );

        /** @var AiRequest $ledger */
        return ['response' => $response, 'request' => $ledger->refresh(), 'tools' => array_map(fn (Tool $tool) => $this->toolName($tool), $tools)];
    }

    /**
     * Build the SDK agent for a user, remembering the conversation.
     */
    public function agent(User $user, AgentVersion $version, ?string $conversationId = null, string $surface = 'page', ?ToolContext $context = null): ConfiguredAgent
    {
        $context ??= new ToolContext($user, $version, $surface, $conversationId);

        $agent = new ConfiguredAgent($version, $this->variables($user), $this->tools->resolve($version->settings()->tools(), $context));

        return $agent->continueOrStart($conversationId, $user);
    }

    /**
     * SDK decisions that resume a run paused on a proposal. Other calls
     * paused in the same turn are rejected (the user decides one at a time).
     */
    public static function decisions(ActionProposal $proposal, bool $approve): Decisions
    {
        return Decisions::from([
            $proposal->tool_call_id => $approve
                ? Decision::approve()
                : Decision::reject('The user cancelled this change. Nothing was done.'),
            '*' => Decision::reject('The user did not confirm this change, so it was not done. Ask again if it is still needed.'),
        ]);
    }

    /**
     * Model to use: an explicit choice (administrators), the model of the
     * version, then the default model of the catalog.
     *
     * @throws ModelNotUsableException
     */
    public function resolveModel(AgentVersion $version, ?AiModel $model = null): AiModel
    {
        $configured = $version->settings()->modelId();

        $candidates = array_filter([
            $model,
            $configured !== null ? AiModel::query()->find($configured) : null,
        ]);

        foreach ($candidates as $candidate) {
            if (AiModel::query()->usable()->forFunction('text')->whereKey($candidate->id)->exists()) {
                return $candidate->loadMissing('connection');
            }
        }

        return self::defaultModel() ?? throw ModelNotUsableException::none();
    }

    /**
     * Default text model: the one marked as default, else the first usable.
     */
    public static function defaultModel(): ?AiModel
    {
        return AiModel::query()
            ->with('connection')
            ->usable()
            ->forFunction('text')
            ->orderByDesc('is_default')
            ->orderByRaw("CASE WHEN status = 'available' THEN 0 ELSE 1 END")
            ->orderBy('id')
            ->first();
    }

    /**
     * Spend of the current month (calculated costs only; unknown costs are
     * reported separately, never as zero).
     */
    public function monthlySpend(?User $user = null): string
    {
        $sum = AiRequest::query()
            ->where('created_at', '>=', Carbon::now()->startOfMonth())
            ->when($user, fn ($query) => $query->where('user_id', $user->getKey()))
            ->sum('cost');

        return (string) BigDecimal::of((string) $sum)->toScale(CostCalculator::SCALE, RoundingMode::HalfUp);
    }

    /**
     * Variables a system prompt may reference.
     *
     * @return array<string, string>
     */
    public function variables(User $user): array
    {
        return [
            'app_name' => (string) $this->settings->get('branding.app_name', config('app.name')),
            'user_name' => $user->name,
            'user_email' => $user->email,
            'user_roles' => $user->getRoleNames()->implode(', '),
            'locale' => app()->getLocale(),
            'date' => Carbon::now()->toDateString(),
            'datetime' => Carbon::now()->toDateTimeString(),
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function providers(AgentVersion $version, AiModel $model): array
    {
        $config = $version->settings();

        $fallbacks = AiModel::query()
            ->with('connection')
            ->usable()
            ->forFunction('text')
            ->whereIn('id', $config->fallbackModelIds())
            ->get()
            ->sortBy(fn (AiModel $fallback) => array_search($fallback->id, $config->fallbackModelIds(), true))
            ->all();

        return $this->gateway->providers($model, $config->retries(), array_values($fallbacks));
    }

    /**
     * @return array{input_chars: int, max_output_tokens: int|null, agent: string, agent_version_id: int, conversation_id: string|null, metadata: array<string, mixed>}
     */
    protected function options(AgentVersion $version, string|Decisions $input, ?string $conversationId): array
    {
        $config = $version->settings();

        return [
            'input_chars' => (is_string($input) ? mb_strlen($input) : 0) + mb_strlen($config->systemPrompt()),
            'max_output_tokens' => $config->maxTokens(),
            'agent' => $version->agent->slug,
            'agent_version_id' => $version->id,
            'conversation_id' => $conversationId,
            'metadata' => ['agent_version' => $version->number] + ($input instanceof Decisions ? ['approval' => true] : []),
        ];
    }

    protected function toolName(Tool $tool): string
    {
        return method_exists($tool, 'name') ? (string) $tool->name() : class_basename($tool);
    }
}
