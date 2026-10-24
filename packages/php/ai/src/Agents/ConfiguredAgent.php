<?php

namespace Starter\Ai\Agents;

use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Concerns\RemembersConversations;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\ConversationStore;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Files\File;
use Laravel\Ai\Files\StoredAudio;
use Laravel\Ai\Files\StoredDocument;
use Laravel\Ai\Files\StoredImage;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Promptable;
use Starter\Ai\Models\AgentVersion;
use Stringable;

/**
 * Laravel AI SDK agent built from a published (or tested draft) agent
 * version: instructions, tools, limits and conversation memory all come
 * from the server-side settings.
 */
class ConfiguredAgent implements Agent, Conversational, HasTools
{
    use Promptable, RemembersConversations;

    protected AgentConfig $config;

    /**
     * Rules added by the server for this turn (trusted, appended to the
     * system prompt), e.g. how to cite retrieved knowledge.
     */
    protected ?string $extraInstructions = null;

    /**
     * Untrusted context for this turn (retrieved passages). It is sent as
     * a separate data message before the prompt, never as instructions,
     * and is not stored in the conversation.
     */
    protected ?string $context = null;

    /**
     * @param  array<string, string|int|float|null>  $variables
     * @param  list<Tool>  $tools
     */
    public function __construct(
        public readonly AgentVersion $version,
        protected array $variables = [],
        protected array $tools = [],
    ) {
        $this->config = $version->settings();
    }

    /**
     * Ground this turn: extra trusted instructions, an untrusted context
     * message and extra tools (for example knowledge_search).
     *
     * @param  list<Tool>  $tools
     */
    public function ground(?string $instructions, ?string $context = null, array $tools = []): static
    {
        $this->extraInstructions = $instructions;
        $this->context = $context;
        $this->tools = [...$this->tools, ...$tools];

        return $this;
    }

    public function instructions(): Stringable|string
    {
        $rendered = $this->config->render($this->variables);

        return $this->extraInstructions !== null ? $rendered."\n\n".$this->extraInstructions : $rendered;
    }

    /**
     * @return list<Tool>
     */
    public function tools(): iterable
    {
        return $this->tools;
    }

    public function maxSteps(): int
    {
        return $this->tools === [] ? 1 : $this->config->maxSteps();
    }

    public function maxTokens(): ?int
    {
        return $this->config->maxTokens();
    }

    public function temperature(): ?float
    {
        return $this->config->temperature();
    }

    public function timeout(): int
    {
        return $this->config->timeout();
    }

    /**
     * Messages of the conversation without the attachments whose file was
     * already deleted by the retention policy.
     */
    public function messages(): iterable
    {
        $messages = [];

        foreach ($this->rememberedMessages() as $message) {
            if ($message instanceof UserMessage && $message->attachments->isNotEmpty()) {
                $available = $message->attachments->filter(fn (mixed $file) => ! $file instanceof File || $this->fileExists($file))->values();

                if ($available->count() !== $message->attachments->count()) {
                    $message = new UserMessage($message->content ?? '', $available);
                }
            }

            $messages[] = $message;
        }

        if ($this->context !== null) {
            $messages[] = new UserMessage($this->context);
        }

        return $messages;
    }

    protected function maxConversationMessages(): int
    {
        return $this->config->historyMessages();
    }

    /**
     * @return iterable<int, mixed>
     */
    protected function rememberedMessages(): iterable
    {
        if (! $this->currentConversation()) {
            return [];
        }

        return resolve(ConversationStore::class)
            ->getLatestConversationMessages($this->currentConversation(), $this->maxConversationMessages())
            ->all();
    }

    protected function fileExists(File $file): bool
    {
        if ($file instanceof StoredImage || $file instanceof StoredDocument || $file instanceof StoredAudio) {
            return Storage::disk($file->disk)->exists($file->path);
        }

        return true;
    }
}
