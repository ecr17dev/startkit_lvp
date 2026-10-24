<?php

namespace Starter\Ai\Catalog;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Starter\Ai\Enums\Driver;
use Starter\Ai\Models\Connection;

/**
 * Lists the models exposed by the provider API of a connection.
 *
 * Being listed does not prove access with the credential: discovered models
 * start as "unknown" until a minimal call verifies them.
 */
class ModelDiscovery
{
    public const TIMEOUT = 15;

    /**
     * @return list<DiscoveredModel>
     *
     * @throws RuntimeException when the provider does not list models
     */
    public function discover(Connection $connection): array
    {
        return match ($connection->driver) {
            Driver::Anthropic => $this->anthropic($connection),
            Driver::Gemini => $this->gemini($connection),
            Driver::Ollama => $this->ollama($connection),
            Driver::OpenRouter => app(OpenRouterCatalog::class)->models($connection),
            Driver::Bedrock, Driver::Azure => throw new RuntimeException((string) __('This provider does not list its models: add them manually.')),
            Driver::Simulated => $this->simulated(),
            default => $this->openAiCompatible($connection),
        };
    }

    /**
     * Built-in models of the simulated provider.
     *
     * @return list<DiscoveredModel>
     */
    protected function simulated(): array
    {
        return [
            new DiscoveredModel('simulated-chat', 'Simulated chat', 'simulated', ['text'], ['text', 'image', 'file'], ['text'], 32000, 4096, [
                'streaming' => 'supported', 'tools' => 'supported', 'structured_output' => 'supported', 'vision' => 'supported', 'documents' => 'supported',
            ]),
            new DiscoveredModel('simulated-transcriber', 'Simulated transcriber', 'simulated', ['transcription'], ['audio'], ['text']),
        ];
    }

    /**
     * @return list<DiscoveredModel>
     */
    protected function openAiCompatible(Connection $connection): array
    {
        $response = $this->http($connection)
            ->withToken((string) $connection->credential('api_key'))
            ->get($connection->endpoint().'/models')
            ->throw();

        return array_values(collect((array) $response->json('data', []))
            ->filter(fn ($item) => is_array($item) && is_string($item['id'] ?? null))
            ->map(fn (array $item) => new DiscoveredModel(
                id: $item['id'],
                provider: is_string($item['owned_by'] ?? null) ? $item['owned_by'] : null,
                functions: $this->guessFunctions($item['id']),
            ))
            ->all());
    }

    /**
     * @return list<DiscoveredModel>
     */
    protected function anthropic(Connection $connection): array
    {
        $models = [];
        $afterId = null;

        do {
            $response = $this->http($connection)
                ->withHeaders(['x-api-key' => (string) $connection->credential('api_key'), 'anthropic-version' => '2023-06-01'])
                ->get($connection->endpoint().'/models', array_filter(['limit' => 100, 'after_id' => $afterId]))
                ->throw();

            foreach ((array) $response->json('data', []) as $item) {
                if (is_array($item) && is_string($item['id'] ?? null)) {
                    $models[] = new DiscoveredModel(
                        id: $item['id'],
                        label: is_string($item['display_name'] ?? null) ? $item['display_name'] : null,
                        provider: 'anthropic',
                        functions: ['text'],
                        inputModalities: ['text', 'image'],
                        outputModalities: ['text'],
                        contextWindow: is_int($item['max_input_tokens'] ?? null) ? $item['max_input_tokens'] : null,
                        maxOutputTokens: is_int($item['max_tokens'] ?? null) ? $item['max_tokens'] : null,
                    );
                }
            }

            $afterId = $response->json('has_more') ? $response->json('last_id') : null;
        } while (is_string($afterId) && count($models) < 1000);

        return $models;
    }

    /**
     * @return list<DiscoveredModel>
     */
    protected function gemini(Connection $connection): array
    {
        $response = $this->http($connection)
            ->withHeaders(['x-goog-api-key' => (string) $connection->credential('api_key')])
            ->get(rtrim((string) $connection->endpoint(), '/').'/models', ['pageSize' => 1000])
            ->throw();

        return array_values(collect((array) $response->json('models', []))
            ->filter(fn ($item) => is_array($item) && is_string($item['name'] ?? null))
            ->map(function (array $item) {
                $methods = (array) ($item['supportedGenerationMethods'] ?? []);

                return new DiscoveredModel(
                    id: Str::after($item['name'], 'models/'),
                    label: is_string($item['displayName'] ?? null) ? $item['displayName'] : null,
                    provider: 'google',
                    functions: array_values(array_filter([
                        in_array('generateContent', $methods, true) ? 'text' : null,
                        in_array('embedContent', $methods, true) ? 'embeddings' : null,
                    ])) ?: null,
                    contextWindow: is_int($item['inputTokenLimit'] ?? null) ? $item['inputTokenLimit'] : null,
                    maxOutputTokens: is_int($item['outputTokenLimit'] ?? null) ? $item['outputTokenLimit'] : null,
                );
            })
            ->all());
    }

    /**
     * @return list<DiscoveredModel>
     */
    protected function ollama(Connection $connection): array
    {
        $response = $this->http($connection)->get($connection->endpoint().'/api/tags')->throw();

        return array_values(collect((array) $response->json('models', []))
            ->filter(fn ($item) => is_array($item) && is_string($item['name'] ?? null))
            ->map(fn (array $item) => new DiscoveredModel(
                id: $item['name'],
                provider: 'ollama',
                functions: $this->guessFunctions($item['name']),
            ))
            ->all());
    }

    protected function http(Connection $connection): PendingRequest
    {
        return Http::timeout(self::TIMEOUT)->acceptJson();
    }

    /**
     * Function hint from well known naming conventions; otherwise unknown (null).
     *
     * @return list<string>|null
     */
    protected function guessFunctions(string $id): ?array
    {
        $id = Str::lower($id);

        return match (true) {
            Str::contains($id, ['embed']) => ['embeddings'],
            Str::contains($id, ['whisper', 'transcribe']) => ['transcription'],
            Str::contains($id, ['tts', 'dall-e', 'image', 'moderation', 'rerank']) => null,
            default => null,
        };
    }
}
