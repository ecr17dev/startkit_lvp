<?php

namespace Starter\Ai\Conversations;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Str;
use Laravel\Ai\Models\Conversation;
use Starter\Ai\Agents\TitleAgent;
use Starter\Ai\AiGateway;
use Starter\Ai\Enums\Feature;
use Starter\Ai\Models\AiModel;
use Throwable;

/**
 * Conversation titles generated through the gateway (so their cost is in
 * the ledger), instead of the SDK's own title generation. The simulated
 * provider and any failure keep a title derived from the first message.
 */
class ConversationTitles
{
    public function __construct(protected AiGateway $gateway) {}

    public function generate(string $conversationId, string $message, AiModel $model, ?Authenticatable $user): string
    {
        $fallback = self::fallback($message);
        $title = $fallback;

        if ($message !== '' && ! $model->connection->driver->isSimulated()) {
            try {
                $text = $this->gateway->run(
                    $model,
                    Feature::Title,
                    $user,
                    fn (string $provider, string $name) => (new TitleAgent)->prompt(Str::limit($message, 2000), provider: $provider, model: $name)->text,
                    ['input_chars' => min(mb_strlen($message), 2000) + 200, 'max_output_tokens' => 24, 'conversation_id' => $conversationId],
                );

                $title = self::clean((string) $text) ?: $fallback;
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        Conversation::query()->whereKey($conversationId)->update(['title' => $title]);

        return $title;
    }

    public static function fallback(string $message): string
    {
        $text = trim((string) preg_replace('/\s+/', ' ', $message));

        return $text === '' ? (string) __('New conversation') : Str::limit($text, 60);
    }

    protected static function clean(string $title): string
    {
        $line = trim((string) strtok(trim($title), "\n"));

        return Str::limit(trim($line, " \t\"'«»“”.#*"), 80, '');
    }
}
