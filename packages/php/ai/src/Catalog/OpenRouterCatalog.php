<?php

namespace Starter\Ai\Catalog;

use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Starter\Ai\Enums\Capability;
use Starter\Ai\Models\Connection;

/**
 * OpenRouter's public models API: a structured, verifiable catalog with
 * modalities, supported parameters and gateway prices (USD per token).
 */
class OpenRouterCatalog
{
    public const URL = 'https://openrouter.ai/api/v1/models';

    /**
     * @return list<DiscoveredModel>
     */
    public function models(?Connection $connection = null): array
    {
        $request = Http::timeout(20)->acceptJson();
        $key = $connection?->credential('api_key');

        if ($key !== null) {
            $request = $request->withToken($key);
        }

        $url = $connection !== null && filled($connection->base_url) ? rtrim((string) $connection->base_url, '/').'/models' : self::URL;

        $data = (array) $request->get($url)->throw()->json('data', []);

        return array_values(collect($data)
            ->filter(fn ($item) => is_array($item) && is_string($item['id'] ?? null))
            ->map(fn (array $item) => $this->map($item))
            ->all());
    }

    /**
     * @param  array<string, mixed>  $item
     */
    protected function map(array $item): DiscoveredModel
    {
        $architecture = (array) ($item['architecture'] ?? []);
        $input = array_values(array_filter((array) ($architecture['input_modalities'] ?? []), 'is_string'));
        $output = array_values(array_filter((array) ($architecture['output_modalities'] ?? []), 'is_string'));
        $parameters = array_values(array_filter((array) ($item['supported_parameters'] ?? []), 'is_string'));
        $id = (string) $item['id'];

        $supported = fn (bool $value) => $value ? 'supported' : 'unsupported';

        $capabilities = $parameters === [] ? [] : [
            Capability::Tools->value => $supported(in_array('tools', $parameters, true)),
            Capability::StructuredOutput->value => $supported(in_array('structured_outputs', $parameters, true) || in_array('response_format', $parameters, true)),
            Capability::Reasoning->value => $supported(in_array('reasoning', $parameters, true) || in_array('include_reasoning', $parameters, true)),
            Capability::Temperature->value => $supported(in_array('temperature', $parameters, true)),
        ];

        if ($input !== []) {
            $capabilities[Capability::Vision->value] = $supported(in_array('image', $input, true));
            $capabilities[Capability::Documents->value] = $supported(in_array('file', $input, true));
            $capabilities[Capability::AudioInput->value] = $supported(in_array('audio', $input, true));
        }

        $pricing = (array) ($item['pricing'] ?? []);
        $prices = [];

        foreach (['prompt' => 'input', 'completion' => 'output', 'input_cache_read' => 'cache_read', 'input_cache_write' => 'cache_write', 'internal_reasoning' => 'reasoning', 'request' => 'request', 'image' => 'image', 'web_search' => 'web_search'] as $field => $component) {
            $value = $pricing[$field] ?? null;

            if (! is_numeric($value) || (float) $value < 0) {
                continue;
            }

            // Token prices are per token: stored per million tokens...
            $prices[$component] = in_array($component, ['input', 'output', 'cache_read', 'cache_write', 'reasoning'], true)
                ? (string) BigDecimal::of((string) $value)->multipliedBy(1_000_000)->strippedOfTrailingZeros()
                : (string) BigDecimal::of((string) $value)->strippedOfTrailingZeros();
        }

        $topProvider = (array) ($item['top_provider'] ?? []);

        return new DiscoveredModel(
            id: $id,
            label: is_string($item['name'] ?? null) ? $item['name'] : null,
            provider: str_contains($id, '/') ? Str::before($id, '/') : null,
            functions: in_array('text', $output, true) || $output === [] ? ['text'] : null,
            inputModalities: $input ?: null,
            outputModalities: $output ?: null,
            contextWindow: is_int($item['context_length'] ?? null) ? $item['context_length'] : null,
            maxOutputTokens: is_int($topProvider['max_completion_tokens'] ?? null) ? $topProvider['max_completion_tokens'] : null,
            capabilities: $capabilities,
            prices: $prices,
        );
    }
}
