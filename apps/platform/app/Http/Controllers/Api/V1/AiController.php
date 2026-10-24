<?php

namespace App\Http\Controllers\Api\V1;

use App\Ai\AiService;
use App\Http\Controllers\Controller;
use App\Models\User;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Laravel\Ai\Models\Conversation;
use Starter\Ai\Agents\AgentManager;
use Starter\Ai\Enums\CostStatus;
use Starter\Ai\Exceptions\AgentLimitExceededException;
use Starter\Ai\Exceptions\AgentNotAvailableException;
use Starter\Ai\Exceptions\BudgetExceededException;
use Starter\Ai\Exceptions\ModelNotUsableException;
use Starter\Ai\Models\Agent;
use Starter\Ai\Models\AiModel;
use Starter\Ai\Models\AiRequest;

#[Group('AI', weight: 3)]
class AiController extends Controller
{
    public function __construct(
        protected AiService $ai,
        protected AgentManager $agents,
    ) {}

    /**
     * List the agents available to the authenticated user through the API.
     */
    public function agents(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json([
            'data' => $this->agents->availableFor($user, 'api')->map(fn (Agent $agent) => [
                'id' => $agent->id,
                'slug' => $agent->slug,
                'name' => $agent->name,
                'description' => $agent->description,
                'is_default' => $agent->is_default,
                'version' => $agent->publishedVersion?->number,
            ])->values(),
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
            /** Slug of the agent to use. Defaults to the default agent available to you. */
            'agent' => ['nullable', 'string', 'max:80'],
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

        try {
            $version = $this->agents->resolve($user, $validated['agent'] ?? null, 'api');

            ['response' => $response, 'request' => $ledger] = $this->ai->chat(
                $user,
                $version,
                $validated['message'],
                model: $canChooseModel && isset($validated['model_id']) ? AiModel::query()->find((int) $validated['model_id']) : null,
                conversationId: $validated['conversation_id'] ?? null,
            );
        } catch (AgentNotAvailableException $exception) {
            return response()->json(['message' => $exception->getMessage()], 404);
        } catch (AgentLimitExceededException $exception) {
            return response()->json(['message' => $exception->getMessage()], 429, $exception->retryAfter > 0 ? ['Retry-After' => (string) $exception->retryAfter] : []);
        } catch (BudgetExceededException $exception) {
            // Quota exhausted, as providers report it...
            return response()->json(['message' => $exception->getMessage()], 429);
        } catch (ModelNotUsableException $exception) {
            return response()->json(['message' => $exception->getMessage()], 503);
        }

        if ($ledger->conversation_id === null && $response->conversationId !== null) {
            $ledger->forceFill(['conversation_id' => $response->conversationId])->save();
        }

        return response()->json([
            'text' => $response->text,
            'conversation_id' => $response->conversationId,
            /** Ledger request id (see the usage endpoints). */
            'request_id' => $ledger->id,
            'agent' => $version->agent->slug,
            /** Published agent version that answered. */
            'agent_version' => $version->number,
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
