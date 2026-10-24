<?php

namespace App\Ai\Knowledge;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Ai\Contracts\ConversationStore;
use Laravel\Ai\Messages\UserMessage;
use Starter\Ai\Agents\ConfiguredAgent;

/**
 * Answer without calling the model when the agent requires evidence and
 * nothing authorized matched: the exchange is stored in the conversation
 * like any other turn, and the abstention is traceable.
 */
class Abstention
{
    public function __construct(protected ConversationStore $store) {}

    public static function message(): string
    {
        return (string) __('I could not find this in the authorized sources, so I cannot answer it with evidence. Try rephrasing the question or ask an administrator to add the missing documentation.');
    }

    /**
     * @return array{conversation_id: string, message_id: string, text: string}
     */
    public function store(User $user, string $question, ?string $conversationId): array
    {
        $text = self::message();
        $type = $user->getMorphClass();
        $conversationId ??= $this->store->storeConversation($type, $user->getKey(), Str::limit(trim($question), 60, '…'));

        $this->store->storeUserMessage($conversationId, $type, $user->getKey(), ConfiguredAgent::class, new UserMessage($question));

        $messageId = (string) Str::uuid7();
        $now = now();

        DB::table((string) config('ai.conversations.tables.messages', 'agent_conversation_messages'))->insert([
            'id' => $messageId,
            'conversation_id' => $conversationId,
            'participant_type' => $type,
            'participant_id' => $user->getKey(),
            'agent' => ConfiguredAgent::class,
            'role' => 'assistant',
            'content' => $text,
            'attachments' => '[]',
            'steps' => '[]',
            'usage' => '[]',
            'meta' => json_encode(['knowledge' => ['outcome' => 'abstained']]),
            'status' => 'completed',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table((string) config('ai.conversations.tables.conversations', 'agent_conversations'))->where('id', $conversationId)->update(['updated_at' => $now]);

        return ['conversation_id' => $conversationId, 'message_id' => $messageId, 'text' => $text];
    }
}
