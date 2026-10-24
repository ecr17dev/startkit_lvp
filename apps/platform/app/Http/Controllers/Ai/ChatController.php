<?php

namespace App\Http\Controllers\Ai;

use App\Actions\Knowledge\RateKnowledge;
use App\Ai\AiService;
use App\Ai\ChatAttachments;
use App\Ai\ChatPresenter;
use App\Ai\Knowledge\Abstention;
use App\Ai\Knowledge\Grounding;
use App\Ai\Knowledge\KnowledgeGrounding;
use App\Ai\ProposalResumer;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Ai\Approvals\PendingApproval;
use Laravel\Ai\Streaming\Events\ToolApprovalRequest;
use Starter\Ai\Agents\ActionProposals;
use Starter\Ai\Agents\AgentManager;
use Starter\Ai\Attachments\AttachmentStore;
use Starter\Ai\Connections\ErrorClassifier;
use Starter\Ai\Exceptions\AgentLimitExceededException;
use Starter\Ai\Exceptions\AgentNotAvailableException;
use Starter\Ai\Exceptions\BudgetExceededException;
use Starter\Ai\Exceptions\ModelNotUsableException;
use Starter\Ai\Exceptions\ProposalNotPendingException;
use Starter\Ai\Jobs\GenerateConversationTitle;
use Starter\Ai\Models\ActionProposal;
use Starter\Ai\Models\AiModel;
use Starter\Ai\Models\AiRequest;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Chat with the configured agents: the chat page, the floating chat (JSON
 * endpoints) and the streaming endpoint they share.
 */
class ChatController extends Controller
{
    public function __construct(
        protected AiService $ai,
        protected AgentManager $agents,
        protected ChatPresenter $presenter,
    ) {}

    /**
     * Show the chat page, optionally opening one of the user's conversations.
     */
    public function index(Request $request, ?string $conversation = null): Response
    {
        $user = $this->actor($request);

        $current = $conversation !== null
            ? ChatPresenter::query($user)->whereKey($conversation)->firstOrFail()
            : null;

        return Inertia::render('ai/Chat', [
            ...$this->presenter->bootstrap($user, 'page'),
            'conversations' => $this->presenter->conversations($user),
            'conversation' => $current !== null ? $this->presenter->conversation($user, $current) : null,
        ]);
    }

    /**
     * Agents, models and permissions for the floating chat.
     */
    public function bootstrap(Request $request): JsonResponse
    {
        $surface = $request->query('surface') === 'page' ? 'page' : 'floating';

        return response()->json($this->presenter->bootstrap($this->actor($request), $surface));
    }

    /**
     * The latest conversations of the user.
     */
    public function conversations(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->presenter->conversations($this->actor($request), 30)]);
    }

    /**
     * One conversation with its messages and proposals.
     */
    public function conversation(Request $request, string $conversation): JsonResponse
    {
        $user = $this->actor($request);

        return response()->json(['data' => $this->presenter->conversation($user, ChatPresenter::query($user)->whereKey($conversation)->firstOrFail())]);
    }

    /**
     * Stream the assistant answer as server-sent events. The input is either
     * a message or the decision on a proposal (which resumes the paused run).
     */
    public function stream(Request $request, ProposalResumer $resumer, ActionProposals $proposals, ChatAttachments $attachments, AttachmentStore $store, KnowledgeGrounding $grounding, Abstention $abstention): StreamedResponse|RedirectResponse
    {
        $user = $this->actor($request);

        $validated = $request->validate([
            'message' => ['required_without:proposal_id', 'nullable', 'string', 'max:20000'],
            'proposal_id' => ['nullable', 'uuid'],
            'decision' => ['required_with:proposal_id', 'nullable', Rule::in(['approve', 'reject'])],
            'conversation_id' => ['nullable', 'string', 'max:36'],
            'agent_id' => ['nullable', 'integer'],
            'model_id' => ['nullable', 'integer', Rule::exists('ai_models', 'id')->where('is_enabled', true)],
            'surface' => ['nullable', Rule::in(['page', 'floating'])],
            'files' => ['nullable', 'array', 'prohibits:proposal_id'],
        ]);

        $surface = $validated['surface'] ?? 'page';
        $model = $user->can('ai.models.manage') && isset($validated['model_id']) ? AiModel::query()->find((int) $validated['model_id']) : null;
        $decided = isset($validated['proposal_id'])
            ? ActionProposal::query()->where('user_id', $user->id)->whereKey((string) $validated['proposal_id'])->firstOrFail()
            : null;

        if ($decided === null && ($validated['conversation_id'] ?? null) !== null) {
            ChatPresenter::query($user)->whereKey($validated['conversation_id'])->firstOrFail();
        }

        try {
            if ($decided !== null) {
                ['version' => $version, 'decisions' => $input, 'conversation_id' => $conversationId] = $resumer->decide($user, $decided, ($validated['decision'] ?? null) === 'approve', $surface);
            } else {
                $conversationId = $validated['conversation_id'] ?? null;

                if ($conversationId !== null) {
                    // A new message abandons the changes still waiting for a decision...
                    $proposals->supersede($conversationId, $user);
                }

                $version = $this->agents->resolve($user, $validated['agent_id'] ?? null, $surface);
                $input = (string) ($validated['message'] ?? '');
                $model = $this->ai->resolveModel($version, $model);

                /** @var array<int, UploadedFile> $files */
                $files = array_values(array_filter((array) $request->file('files', []), fn (mixed $file) => $file instanceof UploadedFile));
                $uploaded = $attachments->accept($files, $user, $version, $model);
            }

            // Retrieve authorized knowledge before answering (agents with RAG)...
            $knowledge = $grounding->prepare($user, $version, $input, $surface, $conversationId);

            if ($knowledge !== null && $knowledge->abstain && is_string($input)) {
                return $this->abstainStream($user, $version->number, $input, $conversationId, $knowledge, $grounding, $abstention);
            }

            ['response' => $stream, 'request' => $ledger, 'release' => $release] = $this->ai->stream(
                $user,
                $version,
                $input,
                $uploaded['files'] ?? [],
                model: $model,
                conversationId: $conversationId,
                surface: $surface,
                grounding: $knowledge,
            );
        } catch (AgentNotAvailableException|AgentLimitExceededException|BudgetExceededException|ModelNotUsableException|ProposalNotPendingException $e) {
            return $this->errorStream($e->getMessage());
        } catch (ValidationException $e) {
            throw $e;
        } catch (Throwable $e) {
            report($e);

            return $this->errorStream(__(ErrorClassifier::classify($e)->message()));
        }

        $stored = $uploaded['models'] ?? [];
        $isNew = $decided === null && ($validated['conversation_id'] ?? null) === null;
        $firstMessage = (string) ($validated['message'] ?? '');

        return response()->stream(function () use ($stream, $ledger, $release, $user, $decided, $proposals, $version, $stored, $store, $isNew, $firstMessage, $knowledge, $grounding) {
            try {
                foreach ($stream as $event) {
                    yield 'data: '.$event."\n\n";

                    // Show the confirmation cards of the calls the run paused on...
                    if ($event instanceof ToolApprovalRequest) {
                        foreach ($this->proposalsFor($user, $event->pendingApprovals->map(fn (PendingApproval $approval) => $approval->id)->values()->all()) as $proposal) {
                            yield 'data: '.json_encode(['type' => 'proposal', 'proposal' => $proposal->present()])."\n\n";
                        }
                    }
                }

                $request = AiRequest::query()->find($ledger->id);

                if ($request !== null && $request->conversation_id === null && $stream->conversationId !== null) {
                    $request->forceFill(['conversation_id' => $stream->conversationId])->save();
                }

                if ($stream->conversationId !== null) {
                    $proposals->attachConversation($ledger->id, $stream->conversationId);
                }

                $store->link($stored, $ledger->id, $stream->conversationId);

                // Validate the citations against the retrieved passages...
                if ($knowledge !== null) {
                    yield 'data: '.json_encode(['type' => 'knowledge', 'knowledge' => $grounding->finish($knowledge, (string) $stream->text, $stream->assistantMessageId, $ledger->id, $stream->conversationId)])."\n\n";
                }

                // The decided proposal ends executed, failed or rejected...
                if ($decided !== null) {
                    yield 'data: '.json_encode(['type' => 'proposal', 'proposal' => $decided->refresh()->present()])."\n\n";
                }

                yield 'data: '.json_encode([
                    'type' => 'summary',
                    'conversation_id' => $stream->conversationId,
                    'request_id' => $ledger->id,
                    'cost' => $request?->cost !== null ? (string) $request->cost : null,
                    'cost_status' => $request?->cost_status->value,
                    'model' => $request?->model,
                    'provider' => $request?->provider,
                    'latency_ms' => $request?->latency_ms,
                    'input_tokens' => $request?->input_tokens,
                    'output_tokens' => $request?->output_tokens,
                    'agent_version' => $version->number,
                ])."\n\n";

                // Name new conversations with the model (recorded in the ledger)...
                if ($isNew && $stream->conversationId !== null && $request?->model_id !== null && config('starter.ai.generate_titles')) {
                    GenerateConversationTitle::dispatch($stream->conversationId, $firstMessage, $request->model_id, $user->id);
                }
            } catch (Throwable $e) {
                Log::error('AI chat stream failed: '.$e->getMessage(), ['exception' => $e]);

                yield 'data: '.json_encode(['type' => 'error', 'message' => __(ErrorClassifier::classify($e)->message())])."\n\n";
            } finally {
                $release();
            }

            yield "data: [DONE]\n\n";
        }, headers: [
            'Cache-Control' => 'no-cache, no-transform',
            'Content-Type' => 'text/event-stream',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    /**
     * @param  array<int, string>  $toolCallIds
     * @return Collection<int, ActionProposal>
     */
    protected function proposalsFor(User $user, array $toolCallIds): Collection
    {
        return ActionProposal::query()->where('user_id', $user->id)->whereIn('tool_call_id', $toolCallIds)->orderBy('created_at')->get();
    }

    /**
     * Feedback on a grounded answer (useful or not), for the knowledge
     * quality indicators.
     */
    public function feedback(Request $request, string $message, RateKnowledge $action): HttpResponse
    {
        $action->answer($this->actor($request), $message, $request->all());

        return response()->noContent();
    }

    /**
     * Rename a conversation.
     */
    public function update(Request $request, string $conversation): RedirectResponse|JsonResponse
    {
        $validated = $request->validate(['title' => ['required', 'string', 'max:100']]);

        ChatPresenter::query($this->actor($request))->whereKey($conversation)->firstOrFail()->update($validated);

        return $request->wantsJson() ? response()->json(status: 204) : back();
    }

    /**
     * Delete a conversation and its messages.
     */
    public function destroy(Request $request, string $conversation): RedirectResponse|JsonResponse
    {
        $model = ChatPresenter::query($this->actor($request))->whereKey($conversation)->firstOrFail();
        $model->messages()->delete();
        $model->delete();

        if ($request->wantsJson()) {
            return response()->json(status: 204);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Conversation deleted.')]);

        return to_route('ai.chat');
    }

    protected function actor(Request $request): User
    {
        /** @var User */
        return $request->user();
    }

    /**
     * Deterministic abstention: no model call, the exchange is stored and
     * the retrieval trace records the outcome.
     */
    protected function abstainStream(User $user, int $versionNumber, string $question, ?string $conversationId, Grounding $knowledge, KnowledgeGrounding $grounding, Abstention $abstention): StreamedResponse
    {
        $stored = $abstention->store($user, $question, $conversationId);
        $summary = $grounding->finish($knowledge, $stored['text'], $stored['message_id'], null, $stored['conversation_id']);

        return response()->stream(function () use ($stored, $summary, $versionNumber) {
            yield 'data: '.json_encode(['type' => 'text_delta', 'delta' => $stored['text']])."\n\n";
            yield 'data: '.json_encode(['type' => 'knowledge', 'knowledge' => $summary])."\n\n";
            yield 'data: '.json_encode([
                'type' => 'summary',
                'conversation_id' => $stored['conversation_id'],
                'request_id' => null,
                'cost' => '0',
                'cost_status' => 'calculated',
                'model' => null,
                'agent_version' => $versionNumber,
            ])."\n\n";
            yield "data: [DONE]\n\n";
        }, headers: [
            'Cache-Control' => 'no-cache, no-transform',
            'Content-Type' => 'text/event-stream',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    protected function errorStream(string $message): StreamedResponse
    {
        return response()->stream(function () use ($message) {
            yield 'data: '.json_encode(['type' => 'error', 'message' => $message])."\n\n";
            yield "data: [DONE]\n\n";
        }, headers: [
            'Cache-Control' => 'no-cache, no-transform',
            'Content-Type' => 'text/event-stream',
        ]);
    }
}
