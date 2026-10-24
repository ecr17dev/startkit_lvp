<?php

namespace App\Ai;

use App\Ai\Agents\Assistant;
use App\Models\AiPrompt;
use App\Models\User;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Carbon;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\StreamableAgentResponse;
use Starter\Administration\Settings\SettingsManager;
use Starter\Ai\AiGateway;
use Starter\Ai\Enums\Feature;
use Starter\Ai\Enums\ModelStatus;
use Starter\Ai\Exceptions\ModelNotUsableException;
use Starter\Ai\Models\AiModel;
use Starter\Ai\Models\AiRequest;
use Starter\Ai\Pricing\CostCalculator;

/**
 * The assistant of the application. Every call goes through the AI gateway:
 * on-demand connection, cost estimate, budget reservation and ledger.
 */
class AiService
{
    public function __construct(
        protected SettingsManager $settings,
        protected AiGateway $gateway,
    ) {}

    /**
     * Send a message and wait for the full response.
     *
     * @return array{response: AgentResponse, request: AiRequest}
     */
    public function chat(User $user, string $message, ?AiPrompt $prompt = null, ?AiModel $model = null, ?string $conversationId = null): array
    {
        $prompt ??= AiPrompt::default();
        $model = $this->resolveModel($prompt, $model);
        $assistant = $this->assistant($user, $prompt, $conversationId);
        $ledger = null;

        $response = $this->gateway->run(
            $model,
            Feature::Chat,
            $user,
            function (string $provider, string $name, AiRequest $request) use ($assistant, $message, &$ledger) {
                $ledger = $request;

                return $assistant->prompt($message, provider: $provider, model: $name);
            },
            $this->options($prompt, $message, $conversationId),
        );

        /** @var AiRequest $ledger */
        return ['response' => $response, 'request' => $ledger->refresh()];
    }

    /**
     * Send a message and stream the response. The ledger request is closed
     * when the stream completes.
     *
     * @return array{response: StreamableAgentResponse, request: AiRequest}
     */
    public function stream(User $user, string $message, ?AiPrompt $prompt = null, ?AiModel $model = null, ?string $conversationId = null): array
    {
        $prompt ??= AiPrompt::default();
        $model = $this->resolveModel($prompt, $model);
        $assistant = $this->assistant($user, $prompt, $conversationId);
        $ledger = null;

        $response = $this->gateway->stream(
            $model,
            Feature::Chat,
            $user,
            function (string $provider, string $name, AiRequest $request) use ($assistant, $message, &$ledger) {
                $ledger = $request;

                return $assistant->stream($message, provider: $provider, model: $name);
            },
            $this->options($prompt, $message, $conversationId),
        );

        /** @var AiRequest $ledger */
        return ['response' => $response, 'request' => $ledger];
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
     * Model to use: the explicit choice, then the prompt's model, then the
     * default model of the catalog.
     *
     * @throws ModelNotUsableException
     */
    public function resolveModel(?AiPrompt $prompt, ?AiModel $model = null): AiModel
    {
        $candidates = array_filter([$model, $prompt?->model]);

        foreach ($candidates as $candidate) {
            $candidate->loadMissing('connection');

            if ($candidate->is_enabled && $candidate->connection->is_active && $candidate->status !== ModelStatus::Unavailable) {
                return $candidate;
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
     * @return array{input_chars: int, max_output_tokens: int|null, agent: string, conversation_id: string|null, metadata: array<string, mixed>}
     */
    protected function options(?AiPrompt $prompt, string $message, ?string $conversationId): array
    {
        return [
            'input_chars' => mb_strlen($message) + mb_strlen((string) $prompt?->content),
            'max_output_tokens' => $prompt?->max_tokens,
            'agent' => Assistant::class,
            'conversation_id' => $conversationId,
            'metadata' => array_filter(['prompt' => $prompt?->slug]),
        ];
    }
}
