<?php

namespace Starter\Ai\Enums;

/**
 * Text drivers of the Laravel AI SDK offered for connections. Every driver
 * maps to an SDK driver name; "openai-compatible" covers custom endpoints.
 */
enum Driver: string
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

    // Local provider without network calls, clearly labelled as simulated...
    case Simulated = 'simulated';

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
            self::Custom => 'OpenAI compatible (custom)',
            self::Simulated => 'Simulated (no network)',
        };
    }

    /**
     * Non secret options shown in the connection form.
     *
     * @return list<string>
     */
    public function options(): array
    {
        return match ($this) {
            self::Bedrock => ['region'],
            self::Azure => ['api_version', 'deployment'],
            default => [],
        };
    }

    /**
     * Secret credentials accepted by the driver (stored encrypted).
     *
     * @return list<string>
     */
    public function credentials(): array
    {
        return match ($this) {
            self::Bedrock => ['api_key', 'access_key_id', 'secret_access_key', 'session_token'],
            self::Simulated => [],
            default => ['api_key'],
        };
    }

    public function requiresApiKey(): bool
    {
        return ! in_array($this, [self::Bedrock, self::Ollama, self::Custom, self::Simulated], true);
    }

    public function requiresBaseUrl(): bool
    {
        return in_array($this, [self::Azure, self::Custom], true);
    }

    /**
     * Default endpoint of the provider (used when the connection has none).
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

    public function isSimulated(): bool
    {
        return $this === self::Simulated;
    }

    /**
     * Whether the provider API lists its models (discovery).
     */
    public function supportsDiscovery(): bool
    {
        return ! in_array($this, [self::Bedrock, self::Azure], true);
    }

    /**
     * @return list<array{value: string, label: string, options: list<string>, credentials: list<string>, requires_api_key: bool, requires_base_url: bool, default_url: string|null, discovery: bool, simulated: bool}>
     */
    public static function catalog(): array
    {
        return array_map(fn (self $driver) => [
            'value' => $driver->value,
            'label' => $driver->label(),
            'options' => $driver->options(),
            'credentials' => $driver->credentials(),
            'requires_api_key' => $driver->requiresApiKey(),
            'requires_base_url' => $driver->requiresBaseUrl(),
            'default_url' => $driver->defaultUrl(),
            'discovery' => $driver->supportsDiscovery(),
            'simulated' => $driver->isSimulated(),
        ], self::cases());
    }
}
