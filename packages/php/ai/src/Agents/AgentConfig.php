<?php

namespace Starter\Ai\Agents;

use Illuminate\Validation\Rule;

/**
 * Settings of an agent version. Stored as JSON, completed with the current
 * defaults when read so older versions keep working when new keys appear.
 *
 * The server resolves these settings: clients never send a system prompt,
 * a model or tools of their own.
 */
final class AgentConfig
{
    /**
     * Variables that a system prompt may reference as {{ name }}.
     */
    public const VARIABLES = ['app_name', 'user_name', 'user_email', 'user_roles', 'locale', 'date', 'datetime'];

    public const LANGUAGES = ['auto', 'es', 'en'];

    public const TONES = ['neutral', 'formal', 'friendly', 'concise'];

    public const STYLES = ['markdown', 'plain'];

    public const SURFACES = ['page', 'floating', 'api'];

    public const IMAGE_TYPES = ['image/png', 'image/jpeg', 'image/webp', 'image/gif'];

    public const DOCUMENT_TYPES = ['application/pdf', 'text/plain', 'text/markdown', 'text/csv'];

    /**
     * Retrieval of the agent: off, before every message ("always") or when
     * the model decides with the knowledge_search tool ("tool").
     */
    public const RAG_MODES = ['off', 'always', 'tool'];

    /**
     * Without enough evidence: abstain ("required") or answer in general
     * terms, clearly identified as not based on the sources ("general").
     */
    public const RAG_EVIDENCE = ['required', 'general'];

    /**
     * @param  array<string, mixed>  $data
     */
    private function __construct(private readonly array $data) {}

    /**
     * @param  array<string, mixed>  $config
     */
    public static function from(array $config): self
    {
        return new self(self::merge(self::defaults(), $config));
    }

    /**
     * @return array<string, mixed>
     */
    public static function defaults(): array
    {
        return [
            'identity' => ['avatar' => null, 'greeting' => null, 'language' => 'auto', 'tone' => 'neutral'],
            'instructions' => ['system_prompt' => 'You are a helpful assistant. Answer clearly and concisely in the language of the user.', 'variables' => ['app_name', 'user_name', 'date']],
            'execution' => ['model_id' => null, 'fallback_model_ids' => [], 'timeout' => 60, 'retries' => 1],
            'responses' => ['max_tokens' => 2048, 'temperature' => null, 'style' => 'markdown'],
            'context' => ['history_messages' => 30, 'retention_days' => null],
            'multimedia' => ['images' => false, 'documents' => false, 'max_files' => 3, 'max_file_mb' => 5, 'mime_types' => [...self::IMAGE_TYPES, 'application/pdf']],
            'microphone' => ['enabled' => false, 'transcription_model_id' => null, 'language' => null, 'max_seconds' => 120, 'auto_send' => false],
            'tools' => ['allowed' => [], 'max_steps' => 5, 'confirm_writes' => false],
            'rag' => ['mode' => 'off', 'collections' => [], 'method' => null, 'top_k' => 6, 'max_context_tokens' => 2000, 'min_score' => null, 'evidence' => 'required', 'min_hits' => 1],
            'access' => ['roles' => [], 'surfaces' => self::SURFACES],
            'limits' => ['requests_per_minute' => 20, 'concurrent_per_user' => 2],
        ];
    }

    /**
     * Validation rules for a config payload nested under the given prefix.
     *
     * @param  list<string>  $toolNames
     * @return array<string, mixed>
     */
    public static function rules(array $toolNames, string $prefix = 'config'): array
    {
        $rules = [
            '' => ['required', 'array'],
            'identity' => ['required', 'array'],
            'identity.avatar' => ['nullable', 'string', 'max:8'],
            'identity.greeting' => ['nullable', 'string', 'max:500'],
            'identity.language' => ['required', Rule::in(self::LANGUAGES)],
            'identity.tone' => ['required', Rule::in(self::TONES)],
            'instructions' => ['required', 'array'],
            'instructions.system_prompt' => ['required', 'string', 'max:20000'],
            'instructions.variables' => ['present', 'array'],
            'instructions.variables.*' => ['string', 'distinct', Rule::in(self::VARIABLES)],
            'execution' => ['required', 'array'],
            'execution.model_id' => ['nullable', 'integer', Rule::exists('ai_models', 'id')],
            'execution.fallback_model_ids' => ['present', 'array', 'max:3'],
            'execution.fallback_model_ids.*' => ['integer', 'distinct', Rule::exists('ai_models', 'id')],
            'execution.timeout' => ['required', 'integer', 'min:5', 'max:600'],
            'execution.retries' => ['required', 'integer', 'min:0', 'max:3'],
            'responses' => ['required', 'array'],
            'responses.max_tokens' => ['nullable', 'integer', 'min:16', 'max:200000'],
            'responses.temperature' => ['nullable', 'numeric', 'min:0', 'max:2'],
            'responses.style' => ['required', Rule::in(self::STYLES)],
            'context' => ['required', 'array'],
            'context.history_messages' => ['required', 'integer', 'min:0', 'max:200'],
            'context.retention_days' => ['nullable', 'integer', 'min:1', 'max:3650'],
            'multimedia' => ['required', 'array'],
            'multimedia.images' => ['required', 'boolean'],
            'multimedia.documents' => ['required', 'boolean'],
            'multimedia.max_files' => ['required', 'integer', 'min:1', 'max:10'],
            'multimedia.max_file_mb' => ['required', 'integer', 'min:1', 'max:20'],
            'multimedia.mime_types' => ['present', 'array'],
            'multimedia.mime_types.*' => ['string', 'distinct', Rule::in([...self::IMAGE_TYPES, ...self::DOCUMENT_TYPES])],
            'microphone' => ['required', 'array'],
            'microphone.enabled' => ['required', 'boolean'],
            'microphone.transcription_model_id' => ['nullable', 'integer', Rule::exists('ai_models', 'id')],
            'microphone.language' => ['nullable', 'string', 'max:10'],
            'microphone.max_seconds' => ['required', 'integer', 'min:5', 'max:600'],
            'microphone.auto_send' => ['required', 'boolean'],
            'tools' => ['required', 'array'],
            'tools.allowed' => ['present', 'array'],
            'tools.allowed.*' => ['string', 'distinct', Rule::in($toolNames)],
            'tools.max_steps' => ['required', 'integer', 'min:1', 'max:20'],
            'tools.confirm_writes' => ['required', 'boolean'],
            'rag' => ['required', 'array'],
            'rag.mode' => ['required', Rule::in(self::RAG_MODES)],
            'rag.collections' => ['present', 'array', 'max:20'],
            'rag.collections.*' => ['integer', 'distinct'],
            'rag.method' => ['nullable', Rule::in(['vector', 'text', 'hybrid'])],
            'rag.top_k' => ['required', 'integer', 'min:1', 'max:20'],
            'rag.max_context_tokens' => ['required', 'integer', 'min:200', 'max:32000'],
            'rag.min_score' => ['nullable', 'numeric', 'min:0', 'max:1'],
            'rag.evidence' => ['required', Rule::in(self::RAG_EVIDENCE)],
            'rag.min_hits' => ['required', 'integer', 'min:1', 'max:10'],
            'access' => ['required', 'array'],
            'access.roles' => ['present', 'array'],
            'access.roles.*' => ['string', 'distinct', Rule::exists('roles', 'name')],
            'access.surfaces' => ['required', 'array', 'min:1'],
            'access.surfaces.*' => ['string', 'distinct', Rule::in(self::SURFACES)],
            'limits' => ['required', 'array'],
            'limits.requests_per_minute' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'limits.concurrent_per_user' => ['nullable', 'integer', 'min:1', 'max:20'],
        ];

        $prefixed = [];

        foreach ($rules as $key => $rule) {
            $prefixed[$key === '' ? $prefix : $prefix.'.'.$key] = $rule;
        }

        return $prefixed;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return data_get($this->data, $key, $default);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->data;
    }

    public function systemPrompt(): string
    {
        return (string) $this->get('instructions.system_prompt');
    }

    /**
     * Render the system prompt: allowed variables are replaced, any other
     * placeholder is kept literally.
     *
     * @param  array<string, string|int|float|null>  $variables
     */
    public function render(array $variables): string
    {
        $allowed = $this->listOfStrings('instructions.variables');

        $prompt = (string) preg_replace_callback(
            '/\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/',
            fn (array $match) => in_array($match[1], $allowed, true) && array_key_exists($match[1], $variables)
                ? (string) $variables[$match[1]]
                : $match[0],
            $this->systemPrompt(),
        );

        return trim($prompt."\n\n".implode("\n", $this->guidance()));
    }

    /**
     * Placeholders used by the system prompt that are not allowed.
     *
     * @return list<string>
     */
    public function unknownVariables(): array
    {
        preg_match_all('/\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/', $this->systemPrompt(), $matches);

        return array_values(array_diff(array_unique($matches[1]), $this->listOfStrings('instructions.variables')));
    }

    public function modelId(): ?int
    {
        return $this->intOrNull('execution.model_id');
    }

    /**
     * @return list<int>
     */
    public function fallbackModelIds(): array
    {
        return array_values(array_map('intval', (array) $this->get('execution.fallback_model_ids', [])));
    }

    public function timeout(): int
    {
        return (int) $this->get('execution.timeout', 60);
    }

    public function retries(): int
    {
        return (int) $this->get('execution.retries', 0);
    }

    public function maxTokens(): ?int
    {
        return $this->intOrNull('responses.max_tokens');
    }

    public function temperature(): ?float
    {
        $value = $this->get('responses.temperature');

        return is_numeric($value) ? (float) $value : null;
    }

    public function historyMessages(): int
    {
        return (int) $this->get('context.history_messages', 30);
    }

    /**
     * @return list<string>
     */
    public function tools(): array
    {
        return $this->listOfStrings('tools.allowed');
    }

    public function maxSteps(): int
    {
        return (int) $this->get('tools.max_steps', 5);
    }

    public function confirmWrites(): bool
    {
        return (bool) $this->get('tools.confirm_writes', false);
    }

    /**
     * @return list<string>
     */
    public function roles(): array
    {
        return $this->listOfStrings('access.roles');
    }

    /**
     * @return list<string>
     */
    public function surfaces(): array
    {
        return $this->listOfStrings('access.surfaces');
    }

    public function requestsPerMinute(): ?int
    {
        return $this->intOrNull('limits.requests_per_minute');
    }

    public function concurrentPerUser(): ?int
    {
        return $this->intOrNull('limits.concurrent_per_user');
    }

    public function acceptsImages(): bool
    {
        return (bool) $this->get('multimedia.images', false);
    }

    public function acceptsDocuments(): bool
    {
        return (bool) $this->get('multimedia.documents', false);
    }

    /**
     * MIME types accepted for uploads, limited to the enabled kinds.
     *
     * @return list<string>
     */
    public function mimeTypes(): array
    {
        return array_values(array_filter($this->listOfStrings('multimedia.mime_types'), fn (string $type) => in_array($type, self::IMAGE_TYPES, true)
            ? $this->acceptsImages()
            : $this->acceptsDocuments()));
    }

    public function maxFiles(): int
    {
        return (int) $this->get('multimedia.max_files', 3);
    }

    public function maxFileKilobytes(): int
    {
        return (int) $this->get('multimedia.max_file_mb', 5) * 1024;
    }

    public function ragMode(): string
    {
        $mode = (string) $this->get('rag.mode', 'off');

        return in_array($mode, self::RAG_MODES, true) ? $mode : 'off';
    }

    /**
     * @return list<int>
     */
    public function ragCollections(): array
    {
        return array_values(array_map('intval', (array) $this->get('rag.collections', [])));
    }

    public function ragEnabled(): bool
    {
        return $this->ragMode() !== 'off' && $this->ragCollections() !== [];
    }

    public function evidenceRequired(): bool
    {
        return $this->get('rag.evidence', 'required') !== 'general';
    }

    public function microphoneEnabled(): bool
    {
        return (bool) $this->get('microphone.enabled', false);
    }

    public function transcriptionModelId(): ?int
    {
        return $this->intOrNull('microphone.transcription_model_id');
    }

    /**
     * Fixed guidance derived from the identity and response settings.
     *
     * @return list<string>
     */
    protected function guidance(): array
    {
        $lines = [];

        $lines[] = match ($this->get('identity.language')) {
            'es' => 'Always answer in Spanish.',
            'en' => 'Always answer in English.',
            default => '',
        };

        $lines[] = match ($this->get('identity.tone')) {
            'formal' => 'Use a formal, professional tone.',
            'friendly' => 'Use a warm, friendly tone.',
            'concise' => 'Be as brief as possible.',
            default => '',
        };

        $lines[] = $this->get('responses.style') === 'plain' ? 'Answer in plain text without Markdown.' : '';

        return array_values(array_filter($lines));
    }

    /**
     * @return list<string>
     */
    protected function listOfStrings(string $key): array
    {
        return array_values(array_filter((array) $this->get($key, []), 'is_string'));
    }

    protected function intOrNull(string $key): ?int
    {
        $value = $this->get($key);

        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * Merge settings into the defaults: maps merge key by key, lists and
     * scalars replace the default entirely.
     *
     * @param  array<string, mixed>  $defaults
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    protected static function merge(array $defaults, array $values): array
    {
        foreach ($defaults as $key => $default) {
            if (! array_key_exists($key, $values)) {
                continue;
            }

            $defaults[$key] = is_array($default) && ! array_is_list($default) && is_array($values[$key])
                ? self::merge($default, $values[$key])
                : $values[$key];
        }

        return $defaults;
    }
}
