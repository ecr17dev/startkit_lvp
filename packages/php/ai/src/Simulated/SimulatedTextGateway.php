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
    /**
     * Start of the knowledge context the application sends before a
     * grounded prompt (and returns from the knowledge_search tool).
     */
    public const KNOWLEDGE_MARKER = '[Knowledge context]';

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

        // Grounded turn: answer extractively from the passages, citing them...
        $context = count($messages) >= 2 ? $messages[count($messages) - 2] : null;

        if ($context instanceof UserMessage && str_starts_with((string) $context->content, self::KNOWLEDGE_MARKER)) {
            $text = $this->extractive((string) $context->content);

            return new StepResponse($text, [], FinishReason::Stop, new TextUsage($input, $this->estimateTokens($text)), $meta);
        }

        $text = (string) __('[Simulated answer] No real AI provider was called. You wrote: ":message" (:words words).', [
            'message' => Str::limit($prompt, 200),
            'words' => str_word_count($prompt),
        ]);

        return new StepResponse($text, [], FinishReason::Stop, new TextUsage($input, $this->estimateTokens($text)), $meta);
    }

    /**
     * First sentences of the first passages with their citation markers.
     */
    protected function extractive(string $context): string
    {
        preg_match_all('/^\[(\d+)\][^\n]*\n(.+?)(?=\n\n\[\d+\]|\z)/ms', $context, $passages, PREG_SET_ORDER);

        if ($passages === []) {
            return (string) __('[Simulated answer] The sources do not contain information about this question.');
        }

        $lines = array_map(function (array $passage) {
            $sentence = Str::limit(trim((string) preg_replace('/\s+/', ' ', (string) preg_replace('/^#+\s.*$/m', '', $passage[2]))), 220);

            return $sentence.' ['.$passage[1].']';
        }, array_slice($passages, 0, 2));

        return (string) __('[Simulated answer based on the sources]')."\n\n".implode("\n\n", $lines);
    }

    /**
     * @param  Collection<int, ToolResult>  $results
     */
    protected function summarize(Collection $results): string
    {
        $knowledge = $results->first(fn (ToolResult $result) => is_string($result->result) && str_starts_with($result->result, self::KNOWLEDGE_MARKER));

        if ($knowledge !== null) {
            return $this->extractive((string) $knowledge->result);
        }

        $lines = $results->map(fn (ToolResult $result) => '- '.$result->name.': '.Str::limit(is_string($result->result) ? $result->result : (string) json_encode($result->result), 600));

        return (string) __('[Simulated summary] Tool results:')."\n".$lines->implode("\n");
    }

    protected function estimateTokens(string $text): int
    {
        return (int) ceil(mb_strlen($text) / 4);
    }
}
