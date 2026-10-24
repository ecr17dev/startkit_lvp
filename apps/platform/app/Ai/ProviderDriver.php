<?php

namespace App\Ai;

enum ProviderDriver: string
{
    case OpenAI = 'openai';
    case Anthropic = 'anthropic';
    case Gemini = 'gemini';
    case DeepSeek = 'deepseek';
    case OpenRouter = 'openrouter';
    case Bedrock = 'bedrock';
    case Azure = 'azure';
    case Groq = 'groq';
    case Mistral = 'mistral';
    case xAI = 'xai';
    case Ollama = 'ollama';
    case Custom = 'openai-compatible';

    /**
     * Get the human readable label of the driver.
     */
    public function label(): string
    {
        return match ($this) {
            self::OpenAI => 'OpenAI',
            self::Anthropic => 'Anthropic',
            self::Gemini => 'Google Gemini',
            self::DeepSeek => 'DeepSeek',
            self::OpenRouter => 'OpenRouter',
            self::Bedrock => 'Amazon Bedrock',
            self::Azure => 'Azure OpenAI',
            self::Groq => 'Groq',
            self::Mistral => 'Mistral',
            self::xAI => 'xAI',
            self::Ollama => 'Ollama',
            self::Custom => 'Custom (OpenAI compatible)',
        };
    }

    /**
     * Get the form fields the driver needs, in display order.
     *
     * @return list<string>
     */
    public function fields(): array
    {
        return match ($this) {
            self::Bedrock => ['region', 'api_key', 'access_key_id', 'secret_access_key', 'session_token'],
            self::Azure => ['api_key', 'base_url', 'api_version', 'deployment'],
            self::Ollama => ['base_url', 'api_key'],
            self::Custom => ['base_url', 'api_key'],
            default => ['api_key', 'base_url'],
        };
    }

    /**
     * Get the fields that must be filled for the driver to work.
     *
     * @return list<string>
     */
    public function requiredFields(): array
    {
        return match ($this) {
            self::Bedrock => ['region'],
            self::Azure => ['api_key', 'base_url', 'deployment'],
            self::Ollama => ['base_url'],
            self::Custom => ['base_url'],
            default => ['api_key'],
        };
    }

    /**
     * Get the default base URL used by the SDK for this driver.
     */
    public function defaultUrl(): ?string
    {
        return match ($this) {
            self::OpenAI => 'https://api.openai.com/v1',
            self::Anthropic => 'https://api.anthropic.com/v1',
            self::Gemini => 'https://generativelanguage.googleapis.com/v1beta/',
            self::DeepSeek => 'https://api.deepseek.com/v1',
            self::OpenRouter => 'https://openrouter.ai/api/v1',
            self::Groq => 'https://api.groq.com/openai/v1',
            self::Mistral => 'https://api.mistral.ai/v1',
            self::xAI => 'https://api.x.ai/v1',
            self::Ollama => 'http://localhost:11434',
            default => null,
        };
    }

    /**
     * Determine whether a default model must be provided explicitly.
     */
    public function requiresModel(): bool
    {
        return in_array($this, [self::Custom, self::Ollama, self::Azure], true);
    }

    /**
     * Get a few suggested models to bootstrap the pricing table.
     *
     * Prices are USD per million tokens. Null prices must be completed
     * by an administrator with the provider's current price list.
     *
     * @return list<array{model: string, label: string, input_price: float|null, output_price: float|null, cache_read_price: float|null, cache_write_price: float|null, context_window: int|null}>
     */
    public function suggestedModels(): array
    {
        $model = fn (string $model, string $label, ?float $input = null, ?float $output = null, ?float $cacheRead = null, ?float $cacheWrite = null, ?int $context = null) => [
            'model' => $model,
            'label' => $label,
            'input_price' => $input,
            'output_price' => $output,
            'cache_read_price' => $cacheRead,
            'cache_write_price' => $cacheWrite,
            'context_window' => $context,
        ];

        return match ($this) {
            self::Anthropic => [
                $model('claude-opus-5-5', 'Claude Opus 5.5', 4.00, 20.00, 0.20, 5.00, 1_000_000),
                $model('claude-sonnet-5-5', 'Claude Sonnet 5.5', 2.00, 10.00, 0.20, 2.50, 1_000_000),
                $model('claude-haiku-5-5', 'Claude Haiku 5.5', 0.10, 0.50, 0.01, 0.125, 1_000_000),
                $model('claude-fable-5-1', 'Claude Fable 5.1', 10.00, 50.00, 0.25, 12.50, 1_000_000),
            ],
            self::OpenAI => [
                $model('gpt-6.1-sol', 'GPT-6.1 Sol'),
                $model('gpt-6-luna', 'GPT-6 Luna'),
                $model('gpt-6-astra', 'GPT-6 Astra'),
            ],
            self::DeepSeek => [
                $model('deepseek-flash', 'DeepSeek Flash'),
                $model('deepseek-v4-pro', 'DeepSeek V4 Pro'),
            ],
            self::OpenRouter => [
                $model('anthropic/claude-sonnet-5.5', 'Claude Sonnet 5.5 (OpenRouter)'),
            ],
            self::Bedrock => [
                $model('global.anthropic.claude-sonnet-5-5', 'Claude Sonnet 5.5 (Bedrock)'),
                $model('global.anthropic.claude-opus-5-5', 'Claude Opus 5.5 (Bedrock)'),
            ],
            default => [],
        };
    }

    /**
     * Get the driver list for select inputs.
     *
     * @return list<array{value: string, label: string, fields: list<string>, required: list<string>, default_url: string|null, requires_model: bool}>
     */
    public static function options(): array
    {
        return array_map(fn (self $driver) => [
            'value' => $driver->value,
            'label' => $driver->label(),
            'fields' => $driver->fields(),
            'required' => $driver->requiredFields(),
            'default_url' => $driver->defaultUrl(),
            'requires_model' => $driver->requiresModel(),
        ], self::cases());
    }
}
