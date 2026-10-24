<?php

namespace App\Ai;

use App\Ai\Knowledge\KnowledgeGrounding;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Laravel\Ai\Models\Conversation;
use Laravel\Ai\Models\ConversationMessage;
use Starter\Ai\Agents\AgentManager;
use Starter\Ai\Models\ActionProposal;
use Starter\Ai\Models\Agent;
use Starter\Ai\Models\AiModel;

/**
 * Data of the chat shared by the chat page and the floating chat.
 */
class ChatPresenter
{
    public function __construct(
        protected AgentManager $agents,
        protected AiService $ai,
    ) {}

    /**
     * What the chat needs to start: agents, models for administrators,
     * whether anything is configured and what the user may see.
     *
     * @return array<string, mixed>
     */
    public function bootstrap(User $user, string $surface): array
    {
        $canChooseModel = $user->can('ai.models.manage');

        return [
            'agents' => $this->agents->availableFor($user, $surface)->map(function (Agent $agent) {
                $config = $agent->publishedVersion?->settings();

                return [
                    'id' => $agent->id,
                    'name' => $agent->name,
                    'description' => $agent->description,
                    'avatar' => $config?->get('identity.avatar'),
                    'greeting' => $config?->get('identity.greeting'),
                    'is_default' => $agent->is_default,
                    'attachments' => $config === null ? null : [
                        'mime_types' => $config->mimeTypes(),
                        'max_files' => $config->maxFiles(),
                        'max_file_mb' => (int) ceil($config->maxFileKilobytes() / 1024),
                    ],
                    'microphone' => $config === null || ! $config->microphoneEnabled() ? null : [
                        'max_seconds' => (int) $config->get('microphone.max_seconds', 120),
                        'auto_send' => (bool) $config->get('microphone.auto_send', false),
                    ],
                ];
            })->values()->all(),
            'models' => $canChooseModel
                ? AiModel::query()->with('connection')->usable()->forFunction('text')->orderByDesc('is_default')->orderBy('model')->get()
                    ->map(fn (AiModel $model) => [
                        'id' => $model->id,
                        'label' => $model->displayName(),
                        'connection' => $model->connection->label,
                        'is_default' => $model->is_default,
                    ])->values()->all()
                : [],
            'canChooseModel' => $canChooseModel,
            'canConfigure' => $user->can('ai.providers.manage') || $user->can('ai.agents.manage'),
            'canSeeDetails' => $user->can('ai.usage.read'),
            'hasModels' => AiService::defaultModel() !== null,
            'monthSpend' => $this->ai->monthlySpend($user),
        ];
    }

    /**
     * @return list<array{id: string, title: string, updated_at: mixed}>
     */
    public function conversations(User $user, int $limit = 50): array
    {
        return array_values(self::query($user)
            ->latest('updated_at')
            ->limit($limit)
            ->get(['id', 'title', 'updated_at'])
            ->map(fn (Conversation $conversation) => [
                'id' => (string) $conversation->getKey(),
                'title' => (string) $conversation->getAttribute('title'),
                'updated_at' => $conversation->getAttribute('updated_at'),
            ])
            ->all());
    }

    /**
     * A conversation with its messages and the proposals made in it.
     *
     * @return array<string, mixed>
     */
    public function conversation(User $user, Conversation $conversation): array
    {
        $messages = [];
        $records = ConversationMessage::query()->where('conversation_id', $conversation->getKey())->whereIn('role', ['user', 'assistant'])
            // Same-second turns: the ids are time ordered (UUIDv7)...
            ->orderBy('created_at')->orderBy('id')->get();
        $knowledge = KnowledgeGrounding::forMessages(array_values($records->where('role', 'assistant')->map(fn ($message) => (string) $message->getKey())->all()));

        foreach ($records as $message) {
            $meta = (array) $message->getAttribute('meta');
            $attachments = json_decode((string) $message->getRawOriginal('attachments'), true);

            $messages[] = [
                'id' => $message->getKey(),
                'role' => $message->getAttribute('role'),
                'content' => $message->getAttribute('content'),
                'usage' => $message->getAttribute('usage'),
                'model' => $meta['model'] ?? null,
                'attachments' => is_array($attachments) ? array_values(array_map(fn (mixed $file) => [
                    'name' => is_array($file) ? ($file['name'] ?? null) : null,
                    'type' => is_array($file) ? ($file['type'] ?? null) : null,
                ], $attachments)) : [],
                'knowledge' => $knowledge[(string) $message->getKey()] ?? ($meta['knowledge'] ?? null),
                'created_at' => $message->getAttribute('created_at'),
            ];
        }

        return [
            'id' => (string) $conversation->getKey(),
            'title' => (string) $conversation->getAttribute('title'),
            'messages' => $messages,
            'proposals' => ActionProposal::query()
                ->where('user_id', $user->id)
                ->where('conversation_id', $conversation->getKey())
                ->orderBy('created_at')
                ->get()
                ->map(fn (ActionProposal $proposal) => $proposal->present())
                ->all(),
        ];
    }

    /**
     * @return Builder<Conversation>
     */
    public static function query(User $user): Builder
    {
        return Conversation::query()
            ->where('participant_type', $user->getMorphClass())
            ->where('participant_id', $user->getKey());
    }
}
