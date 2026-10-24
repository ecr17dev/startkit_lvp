<?php

namespace App\Http\Controllers\Api\V1;

use App\Ai\AiService;
use App\Http\Controllers\Controller;
use App\Models\AiPrompt;
use App\Models\User;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Laravel\Ai\Models\Conversation;
use Starter\Ai\Enums\CostStatus;
use Starter\Ai\Models\AiModel;
use Starter\Ai\Models\AiRequest;

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
            /** Catalog model id (requires the `ai.models.manage` permission). */
            'model_id' => ['nullable', 'integer', Rule::exists('ai_models', 'id')->where('is_enabled', true)],
        ]);

        if (($validated['conversation_id'] ?? null) !== null) {
            Conversation::query()
                ->where('participant_type', $user->getMorphClass())
                ->where('participant_id', $user->getKey())
                ->whereKey($validated['conversation_id'])
                ->firstOrFail();
        }

        $canChooseModel = $user->can('ai.models.manage');

        ['response' => $response, 'request' => $ledger] = $this->ai->chat(
            $user,
            $validated['message'],
            prompt: isset($validated['prompt']) ? AiPrompt::query()->where('slug', $validated['prompt'])->first() : null,
            model: $canChooseModel && isset($validated['model_id']) ? AiModel::query()->find((int) $validated['model_id']) : null,
            conversationId: $validated['conversation_id'] ?? null,
        );

        if ($ledger->conversation_id === null && $response->conversationId !== null) {
            $ledger->forceFill(['conversation_id' => $response->conversationId])->save();
        }

        return response()->json([
            'text' => $response->text,
            'conversation_id' => $response->conversationId,
            /** Ledger request id (see the usage endpoints). */
            'request_id' => $ledger->id,
            'provider' => $ledger->provider,
            'model' => $ledger->model,
            'usage' => [
                'input_tokens' => $ledger->input_tokens,
                'output_tokens' => $ledger->output_tokens,
            ],
            /** Calculated cost in USD, or null when a price is unknown (never zero by default). */
            'cost' => $ledger->cost !== null ? (string) $ledger->cost : null,
            /** @var 'calculated'|'unknown'|'pending'|'estimated'|'reconciled' */
            'cost_status' => $ledger->cost_status->value,
        ]);
    }

    /**
     * Get the AI usage of the authenticated user for the current month.
     */
    public function usage(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $requests = AiRequest::query()
            ->where('user_id', $user->getKey())
            ->where('created_at', '>=', Carbon::now()->startOfMonth());

        return response()->json([
            'calls' => (clone $requests)->count(),
            'input_tokens' => (int) (clone $requests)->sum('input_tokens'),
            'output_tokens' => (int) (clone $requests)->sum('output_tokens'),
            /** Sum of the known costs in USD. */
            'cost' => (string) (clone $requests)->sum('cost'),
            /** Requests whose cost is unknown (missing prices). */
            'unknown_cost_calls' => (clone $requests)->where('cost_status', CostStatus::Unknown->value)->count(),
        ]);
    }
}
