<?php

namespace App\Http\Controllers\Api\V1;

use App\Ai\AiService;
use App\Http\Controllers\Controller;
use App\Models\AiPrompt;
use App\Models\AiUsageLog;
use App\Models\User;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Laravel\Ai\Models\Conversation;

#[Group('AI', weight: 3)]
class AiController extends Controller
{
    public function __construct(protected AiService $ai) {}

    /**
     * List the available system prompts.
     */
    public function prompts(): JsonResponse
    {
        return response()->json([
            'data' => AiPrompt::query()->active()->orderByDesc('is_default')->orderBy('name')
                ->get(['id', 'name', 'slug', 'description', 'is_default']),
        ]);
    }

    /**
     * Send a message to the assistant.
     *
     * Pass the returned `conversation_id` in the next request to keep the context.
     * Requires the `ai.chat` permission and a token with the `ai` ability.
     */
    public function chat(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $validated = $request->validate([
            'message' => ['required', 'string', 'max:20000'],
            /** Slug of the system prompt to use. Defaults to the default prompt. */
            'prompt' => ['nullable', 'string', Rule::exists('ai_prompts', 'slug')->where('is_active', true)],
            /** Conversation to continue. */
            'conversation_id' => ['nullable', 'string', 'max:36'],
            /** Provider name (requires the `ai.models.manage` permission). */
            'provider' => ['nullable', 'string', Rule::exists('ai_providers', 'name')->where('is_active', true)],
            /** Model identifier (requires the `ai.models.manage` permission). */
            'model' => ['nullable', 'string', 'max:255'],
        ]);

        if (($validated['conversation_id'] ?? null) !== null) {
            Conversation::query()
                ->where('participant_type', $user->getMorphClass())
                ->where('participant_id', $user->getKey())
                ->whereKey($validated['conversation_id'])
                ->firstOrFail();
        }

        $canChooseModel = $user->can('ai.models.manage');

        $response = $this->ai->chat(
            $user,
            $validated['message'],
            prompt: isset($validated['prompt']) ? AiPrompt::query()->where('slug', $validated['prompt'])->first() : null,
            provider: $canChooseModel ? ($validated['provider'] ?? null) : null,
            model: $canChooseModel ? ($validated['model'] ?? null) : null,
            conversationId: $validated['conversation_id'] ?? null,
            source: 'api',
        );

        $log = AiUsageLog::query()->where('invocation_id', $response->invocationId)->latest('id')->first();

        return response()->json([
            'text' => $response->text,
            'conversation_id' => $response->conversationId,
            'provider' => $log?->provider,
            'model' => $log !== null ? $log->model : $response->meta->model,
            'usage' => [
                'input_tokens' => $response->usage->inputTokens,
                'output_tokens' => $response->usage->outputTokens,
            ],
            /** Estimated cost in USD (0 when the model has no price configured). */
            'cost' => $log !== null ? (float) $log->cost : 0.0,
        ]);
    }

    /**
     * Get the AI usage of the authenticated user for the current month.
     */
    public function usage(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $logs = AiUsageLog::query()
            ->where('user_id', $user->getKey())
            ->where('created_at', '>=', Carbon::now()->startOfMonth());

        return response()->json([
            'calls' => (clone $logs)->count(),
            'input_tokens' => (int) (clone $logs)->sum('input_tokens'),
            'output_tokens' => (int) (clone $logs)->sum('output_tokens'),
            'cost' => (float) (clone $logs)->sum('cost'),
            'budget' => settings('ai.user_monthly_budget'),
        ]);
    }
}
