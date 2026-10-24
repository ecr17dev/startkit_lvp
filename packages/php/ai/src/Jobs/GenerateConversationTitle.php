<?php

namespace Starter\Ai\Jobs;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Starter\Ai\Conversations\ConversationTitles;
use Starter\Ai\Models\AiModel;
use Starter\Identity\Identity;

/**
 * Title of a new conversation, generated after the first answer.
 */
class GenerateConversationTitle implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(
        public string $conversationId,
        public string $message,
        public int $modelId,
        public ?int $userId = null,
    ) {}

    public function handle(ConversationTitles $titles): void
    {
        $model = AiModel::query()->with('connection')->find($this->modelId);

        if ($model === null) {
            return;
        }

        $userModel = Identity::userModel();
        $user = $this->userId !== null ? $userModel::query()->find($this->userId) : null;

        $titles->generate($this->conversationId, $this->message, $model, $user instanceof Authenticatable ? $user : null);
    }
}
