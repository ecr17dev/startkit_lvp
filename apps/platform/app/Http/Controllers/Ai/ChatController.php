<?php

namespace App\Http\Controllers\Ai;

use App\Ai\AiService;
use App\Ai\Exceptions\BudgetExceededException;
use App\Http\Controllers\Controller;
use App\Models\AiModel;
use App\Models\AiPrompt;
use App\Models\AiProvider;
use App\Models\AiUsageLog;
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
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class ChatController extends Controller
{
    public function __construct(protected AiService $ai) {}

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

        $canChooseModel = $user->can('ai.choose-model');

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
            'prompts' => AiPrompt::query()->active()->orderByDesc('is_default')->orderBy('name')->get(['id', 'name', 'description', 'is_default']),
            'providers' => $canChooseModel
                ? AiProvider::query()->active()->with(['models' => fn ($query) => $query->where('is_active', true)->orderBy('model')])->orderByDesc('is_default')->orderBy('label')->get()
                    ->map(fn (AiProvider $provider) => [
                        'name' => $provider->name,
                        'label' => $provider->label,
                        'default_model' => $provider->default_model,
                        'models' => $provider->models->map(fn (AiModel $model) => ['model' => $model->model, 'label' => $model->label ?: $model->model])->values(),
                    ])
                : [],
            'canChooseModel' => $canChooseModel,
            'hasProviders' => AiProvider::query()->active()->exists(),
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
            'prompt_id' => ['nullable', 'integer', Rule::exists('ai_prompts', 'id')->where('is_active', true)],
            'provider' => ['nullable', 'string', Rule::exists('ai_providers', 'name')->where('is_active', true)],
            'model' => ['nullable', 'string', 'max:255'],
        ]);

        if (($validated['conversation_id'] ?? null) !== null) {
            $this->conversations($user)->whereKey($validated['conversation_id'])->firstOrFail();
        }

        $canChooseModel = $user->can('ai.choose-model');

        $prompt = isset($validated['prompt_id']) ? AiPrompt::query()->whereKey($validated['prompt_id'])->first() : null;

        try {
            $stream = $this->ai->stream(
                $user,
                $validated['message'],
                prompt: $prompt,
                provider: $canChooseModel ? ($validated['provider'] ?? null) : null,
                model: $canChooseModel ? ($validated['model'] ?? null) : null,
                conversationId: $validated['conversation_id'] ?? null,
            );
        } catch (BudgetExceededException $e) {
            return $this->errorStream($e->getMessage());
        } catch (Throwable $e) {
            report($e);

            return $this->errorStream($e->getMessage());
        }

        return response()->stream(function () use ($stream) {
            try {
                foreach ($stream as $event) {
                    yield 'data: '.$event."\n\n";
                }

                $log = AiUsageLog::query()->where('invocation_id', $stream->invocationId)->latest('id')->first();

                yield 'data: '.json_encode([
                    'type' => 'summary',
                    'conversation_id' => $stream->conversationId,
                    'cost' => $log !== null ? (float) $log->cost : null,
                    'priced' => $log?->priced,
                    'model' => $log?->model,
                ])."\n\n";
            } catch (Throwable $e) {
                Log::error('AI chat stream failed: '.$e->getMessage(), ['exception' => $e]);

                yield 'data: '.json_encode(['type' => 'error', 'message' => $e->getMessage()])."\n\n";
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
