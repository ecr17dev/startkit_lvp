<?php

namespace App\Http\Controllers\Ai;

use App\Ai\AiService;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Ai\Models\Conversation;
use Laravel\Ai\Models\ConversationMessage;
use Starter\Ai\Agents\AgentManager;
use Starter\Ai\Connections\ErrorClassifier;
use Starter\Ai\Exceptions\AgentLimitExceededException;
use Starter\Ai\Exceptions\AgentNotAvailableException;
use Starter\Ai\Exceptions\BudgetExceededException;
use Starter\Ai\Exceptions\ModelNotUsableException;
use Starter\Ai\Models\Agent;
use Starter\Ai\Models\AiModel;
use Starter\Ai\Models\AiRequest;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class ChatController extends Controller
{
    public function __construct(
        protected AiService $ai,
        protected AgentManager $agents,
    ) {}

    /**
     * Show the chat, optionally opening one of the user's conversations.
     */
    public function index(Request $request, ?string $conversation = null): Response
    {
        /** @var User $user */
        $user = $request->user();

        $current = $conversation !== null
            ? $this->conversations($user)->whereKey($conversation)->firstOrFail()
            : null;

        $canChooseModel = $user->can('ai.models.manage');

        return Inertia::render('ai/Chat', [
            'conversations' => $this->conversations($user)
                ->latest('updated_at')
                ->limit(50)
                ->get(['id', 'title', 'updated_at']),
            'conversation' => $current ? [
                'id' => $current->getKey(),
                'title' => $current->getAttribute('title'),
                'messages' => $this->messages($current),
            ] : null,
            'agents' => $this->agents->availableFor($user, 'page')->map(fn (Agent $agent) => [
                'id' => $agent->id,
                'name' => $agent->name,
                'description' => $agent->description,
                'avatar' => $agent->publishedVersion?->settings()->get('identity.avatar'),
                'greeting' => $agent->publishedVersion?->settings()->get('identity.greeting'),
                'is_default' => $agent->is_default,
            ])->values(),
            'models' => $canChooseModel
                ? AiModel::query()->with('connection')->usable()->forFunction('text')->orderByDesc('is_default')->orderBy('model')->get()
                    ->map(fn (AiModel $model) => [
                        'id' => $model->id,
                        'label' => $model->displayName(),
                        'connection' => $model->connection->label,
                        'is_default' => $model->is_default,
                    ])
                : [],
            'defaultModelId' => AiService::defaultModel()?->id,
            'canChooseModel' => $canChooseModel,
            'hasModels' => AiService::defaultModel() !== null,
            'monthSpend' => $this->ai->monthlySpend($user),
        ]);
    }

    /**
     * Stream the assistant answer as server-sent events.
     */
    public function stream(Request $request): StreamedResponse|RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $validated = $request->validate([
            'message' => ['required', 'string', 'max:20000'],
            'conversation_id' => ['nullable', 'string', 'max:36'],
            'agent_id' => ['nullable', 'integer'],
            'model_id' => ['nullable', 'integer', Rule::exists('ai_models', 'id')->where('is_enabled', true)],
        ]);

        if (($validated['conversation_id'] ?? null) !== null) {
            $this->conversations($user)->whereKey($validated['conversation_id'])->firstOrFail();
        }

        $canChooseModel = $user->can('ai.models.manage');

        $model = $canChooseModel && isset($validated['model_id']) ? AiModel::query()->find((int) $validated['model_id']) : null;

        try {
            $version = $this->agents->resolve($user, $validated['agent_id'] ?? null, 'page');

            ['response' => $stream, 'request' => $ledger, 'release' => $release] = $this->ai->stream(
                $user,
                $version,
                $validated['message'],
                model: $model,
                conversationId: $validated['conversation_id'] ?? null,
            );
        } catch (AgentNotAvailableException|AgentLimitExceededException|BudgetExceededException|ModelNotUsableException $e) {
            return $this->errorStream($e->getMessage());
        } catch (Throwable $e) {
            report($e);

            return $this->errorStream(__(ErrorClassifier::classify($e)->message()));
        }

        return response()->stream(function () use ($stream, $ledger, $release) {
            try {
                foreach ($stream as $event) {
                    yield 'data: '.$event."\n\n";
                }

                $request = AiRequest::query()->find($ledger->id);

                if ($request !== null && $request->conversation_id === null && $stream->conversationId !== null) {
                    $request->forceFill(['conversation_id' => $stream->conversationId])->save();
                }

                yield 'data: '.json_encode([
                    'type' => 'summary',
                    'conversation_id' => $stream->conversationId,
                    'request_id' => $ledger->id,
                    'cost' => $request?->cost !== null ? (string) $request->cost : null,
                    'cost_status' => $request?->cost_status->value,
                    'model' => $request?->model,
                ])."\n\n";
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
     * Rename a conversation.
     */
    public function update(Request $request, string $conversation): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $validated = $request->validate(['title' => ['required', 'string', 'max:100']]);

        $this->conversations($user)->whereKey($conversation)->firstOrFail()->update($validated);

        return back();
    }

    /**
     * Delete a conversation and its messages.
     */
    public function destroy(Request $request, string $conversation): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $model = $this->conversations($user)->whereKey($conversation)->firstOrFail();
        $model->messages()->delete();
        $model->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Conversation deleted.')]);

        return to_route('ai.chat');
    }

    /**
     * Get the user and assistant messages of a conversation.
     *
     * @return list<array<string, mixed>>
     */
    protected function messages(Conversation $conversation): array
    {
        $messages = [];

        foreach (ConversationMessage::query()->where('conversation_id', $conversation->getKey())->whereIn('role', ['user', 'assistant'])->oldest()->get() as $message) {
            $meta = (array) $message->getAttribute('meta');

            $messages[] = [
                'id' => $message->getKey(),
                'role' => $message->getAttribute('role'),
                'content' => $message->getAttribute('content'),
                'usage' => $message->getAttribute('usage'),
                'model' => $meta['model'] ?? null,
                'created_at' => $message->getAttribute('created_at'),
            ];
        }

        return $messages;
    }

    /**
     * @return Builder<Conversation>
     */
    protected function conversations(User $user): Builder
    {
        return Conversation::query()
            ->where('participant_type', $user->getMorphClass())
            ->where('participant_id', $user->getKey());
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
