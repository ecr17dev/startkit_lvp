<?php

namespace Starter\Ai\Simulated;

use Generator;
use Illuminate\JsonSchema\Types\ObjectType;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Laravel\Ai\Contracts\Gateway\StepTextGateway;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Gateway\StepContext;
use Laravel\Ai\Gateway\StepResponse;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Messages\ToolResultMessage;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Responses\Data\FinishReason;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\TextUsage;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\ToolResult;
use Laravel\Ai\Streaming\Events\StreamStart;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Streaming\Events\TextEnd;
use Laravel\Ai\Streaming\Events\TextStart;
use Laravel\Ai\Streaming\Events\ToolCall as ToolCallEvent;
use Laravel\Ai\Tools\ToolNameResolver;

use function Laravel\Ai\generate_fake_data_for_json_schema_type;
use function Laravel\Ai\ulid;

/**
 * Deterministic text generation:
 *
 * - "/tool name {json}" calls the named tool with those arguments, so tool
 *   and confirmation flows can be exercised without a real model;
 * - after tool results it summarizes them;
 * - otherwise it echoes a labelled answer.
 *
 * Token usage is estimated (4 characters per token).
 */
class SimulatedTextGateway implements StepTextGateway
{
    public function generateTextStep(
        TextProvider $provider,
        string $model,
        ?string $instructions,
        array $messages,
        array $tools,
        ?array $schema,
        ?TextGenerationOptions $options,
        ?int $timeout,
        StepContext $stepContext,
    ): StepResponse {
        return $this->step($provider, $model, $instructions, $messages, $tools, $schema);
    }

    public function generateStreamStep(
        string $invocationId,
        TextProvider $provider,
        string $model,
        ?string $instructions,
        array $messages,
        array $tools,
        ?array $schema,
        ?TextGenerationOptions $options,
        ?int $timeout,
        StepContext $stepContext,
    ): Generator {
        $step = $this->step($provider, $model, $instructions, $messages, $tools, $schema);
        $messageId = ulid();

        yield (new StreamStart(ulid(), $provider->name(), $model, time()))->withInvocationId($invocationId);

        if ($step->text !== '') {
            yield (new TextStart(ulid(), $messageId, time()))->withInvocationId($invocationId);

            foreach (Str::of($step->text)->explode(' ') as $index => $word) {
                yield (new TextDelta(ulid(), $messageId, $index > 0 ? ' '.$word : $word, time()))->withInvocationId($invocationId);
            }

            yield (new TextEnd(ulid(), $messageId, time()))->withInvocationId($invocationId);
        }

        foreach ($step->toolCalls as $toolCall) {
            yield (new ToolCallEvent(ulid(), $toolCall, time()))->withInvocationId($invocationId);
        }

        return $step;
    }

    /**
     * @param  Message[]  $messages
     * @param  Tool[]  $tools
     * @param  array<string, mixed>|null  $schema
     */
    protected function step(TextProvider $provider, string $model, ?string $instructions, array $messages, array $tools, ?array $schema): StepResponse
    {
        $last = end($messages) ?: null;
        $input = $this->estimateTokens((string) $instructions) + $this->estimateTokens(json_encode(array_map(fn ($message) => $message instanceof UserMessage ? $message->content : '', $messages)) ?: '');
        $meta = new Meta($provider->name(), $model);

        if ($schema !== null) {
            $structured = generate_fake_data_for_json_schema_type(new ObjectType($schema));
            $text = (string) json_encode($structured);

            return new StepResponse($text, [], FinishReason::Stop, new TextUsage($input, $this->estimateTokens($text)), $meta, $structured);
        }

        if ($last instanceof ToolResultMessage) {
            $text = $this->summarize($last->toolResults);

            return new StepResponse($text, [], FinishReason::Stop, new TextUsage($input, $this->estimateTokens($text)), $meta);
        }

        $prompt = $last instanceof UserMessage ? trim($last->content) : '';

        if (preg_match('/^\/tool\s+([a-z][a-z0-9_]*)\s*(\{.*\})?\s*$/s', $prompt, $match) === 1) {
            $available = array_map(fn (Tool $tool) => ToolNameResolver::resolve($tool), $tools);

            if (in_array($match[1], $available, true)) {
                $arguments = json_decode($match[2] ?? '{}', true);

                return new StepResponse('', [new ToolCall('call_'.Str::lower((string) Str::ulid()), $match[1], is_array($arguments) ? $arguments : [])], FinishReason::ToolCalls, new TextUsage($input, 20), $meta);
            }

            $text = (string) __('[Simulated] The tool :tool is not available to you in this agent.', ['tool' => $match[1]]);

            return new StepResponse($text, [], FinishReason::Stop, new TextUsage($input, $this->estimateTokens($text)), $meta);
        }

        $text = (string) __('[Simulated answer] No real AI provider was called. You wrote: ":message" (:words words).', [
            'message' => Str::limit($prompt, 200),
            'words' => str_word_count($prompt),
        ]);

        return new StepResponse($text, [], FinishReason::Stop, new TextUsage($input, $this->estimateTokens($text)), $meta);
    }

    /**
     * @param  Collection<int, ToolResult>  $results
     */
    protected function summarize(Collection $results): string
    {
        $lines = $results->map(fn (ToolResult $result) => '- '.$result->name.': '.Str::limit(is_string($result->result) ? $result->result : (string) json_encode($result->result), 600));

        return (string) __('[Simulated summary] Tool results:')."\n".$lines->implode("\n");
    }

    protected function estimateTokens(string $text): int
    {
        return (int) ceil(mb_strlen($text) / 4);
    }
}
