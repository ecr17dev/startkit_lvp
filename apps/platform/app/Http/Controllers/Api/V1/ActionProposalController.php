<?php

namespace App\Http\Controllers\Api\V1;

use App\Ai\AiService;
use App\Ai\ProposalResumer;
use App\Http\Controllers\Controller;
use App\Models\User;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Starter\Ai\Enums\ProposalStatus;
use Starter\Ai\Exceptions\AgentLimitExceededException;
use Starter\Ai\Exceptions\AgentNotAvailableException;
use Starter\Ai\Exceptions\BudgetExceededException;
use Starter\Ai\Exceptions\ModelNotUsableException;
use Starter\Ai\Exceptions\ProposalNotPendingException;
use Starter\Ai\Models\ActionProposal;

/**
 * Changes prepared by the agent tools that wait for your confirmation.
 * Confirming resumes the conversation: the tool runs with the confirmed
 * arguments, after checking again permissions and the resource state.
 */
#[Group('AI', weight: 3)]
class ActionProposalController extends Controller
{
    public function __construct(
        protected AiService $ai,
        protected ProposalResumer $resumer,
    ) {}

    /**
     * List your proposals, newest first.
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['nullable', Rule::enum(ProposalStatus::class)],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
        ]);

        $page = ActionProposal::query()
            ->where('user_id', $this->actor($request)->id)
            ->when($validated['status'] ?? null, fn ($query, string $status) => $query->where('status', $status))
            ->latest()
            ->paginate((int) ($validated['per_page'] ?? 20));

        return response()->json([
            'data' => collect($page->items())->map(fn (ActionProposal $proposal) => $proposal->present())->values(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    /**
     * Show one of your proposals: target, before/after values and effects.
     */
    public function show(Request $request, string $proposal): JsonResponse
    {
        return response()->json(['data' => $this->find($request, $proposal)->present()]);
    }

    /**
     * Confirm a pending proposal and let the agent finish its answer.
     */
    public function confirm(Request $request, string $proposal): JsonResponse
    {
        return $this->decide($request, $this->find($request, $proposal), true);
    }

    /**
     * Cancel a pending proposal. Nothing is changed.
     */
    public function reject(Request $request, string $proposal): JsonResponse
    {
        return $this->decide($request, $this->find($request, $proposal), false);
    }

    protected function decide(Request $request, ActionProposal $proposal, bool $approve): JsonResponse
    {
        $user = $this->actor($request);

        try {
            ['version' => $version, 'decisions' => $decisions, 'conversation_id' => $conversationId] = $this->resumer->decide($user, $proposal, $approve, 'api');

            ['response' => $response, 'request' => $ledger] = $this->ai->chat($user, $version, $decisions, conversationId: $conversationId, surface: 'api');
        } catch (ProposalNotPendingException $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        } catch (AgentNotAvailableException $exception) {
            return response()->json(['message' => $exception->getMessage()], 404);
        } catch (AgentLimitExceededException|BudgetExceededException $exception) {
            return response()->json(['message' => $exception->getMessage()], 429);
        } catch (ModelNotUsableException $exception) {
            return response()->json(['message' => $exception->getMessage()], 503);
        }

        return response()->json([
            'data' => $proposal->refresh()->present(),
            /** Final answer of the agent after the decision. */
            'text' => $response->text,
            'conversation_id' => $conversationId,
            'request_id' => $ledger->id,
        ]);
    }

    protected function find(Request $request, string $id): ActionProposal
    {
        return ActionProposal::query()->where('user_id', $this->actor($request)->id)->whereKey($id)->firstOrFail();
    }

    protected function actor(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
