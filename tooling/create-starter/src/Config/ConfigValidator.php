<?php

namespace Starter\Installer\Config;

use Starter\Installer\JsonSchema\Validator;

/**
 * Validates a starter.config.json: the JSON Schema of the contract, plain
 * secrets anywhere in the file and the cross rules the schema cannot
 * express (RAG without a connection or embedding profile, references to
 * undeclared AI connections, external databases without a reference...).
 *
 * Errors block an installation; warnings describe pending configuration
 * (credentials not defined yet, owner missing, simulated provider).
 */
final class ConfigValidator
{
    /**
     * Patterns of well known credentials that must never be in the file.
     *
     * @var array<string, string>
     */
    public const SECRET_PATTERNS = [
        'API key' => '/\b(sk|rk)-(ant-|proj-|or-)?[A-Za-z0-9_-]{16,}/',
        'AWS access key' => '/\b(AKIA|ASIA)[0-9A-Z]{16}\b/',
        'private key' => '/-----BEGIN [A-Z ]*PRIVATE KEY-----/',
        'GitHub token' => '/\bgh[pousr]_[A-Za-z0-9]{20,}/',
        'Slack token' => '/\bxox[abprs]-[A-Za-z0-9-]{10,}/',
        'Google API key' => '/\bAIza[0-9A-Za-z_-]{30,}/',
        'Resend API key' => '/\bre_[A-Za-z0-9]{6,}_[A-Za-z0-9]{12,}/',
        'URL with credentials' => '#[a-z][a-z0-9+.-]*://[^/\s:@]+:[^/\s@]+@#i',
    ];

    /**
     * @var array<string, mixed>
     */
    private array $schema;

    /**
     * @param  array<string, mixed>|null  $schema
     */
    public function __construct(?array $schema = null)
    {
        $this->schema = $schema ?? self::schema();
    }

    public static function schemaPath(): string
    {
        return dirname(__DIR__, 2).'/resources/starter.config.schema.json';
    }

    /**
     * @return array<string, mixed>
     */
    public static function schema(): array
    {
        $schema = json_decode((string) file_get_contents(self::schemaPath()), true);

        return is_array($schema) ? $schema : [];
    }

    /**
     * @return array{valid: bool, errors: list<string>, warnings: list<string>, config: array<string, mixed>|null}
     */
    public function validateFile(string $path): array
    {
        if (! is_file($path) || ! is_readable($path)) {
            return ['valid' => false, 'errors' => ["/: file not found or not readable: {$path}"], 'warnings' => [], 'config' => null];
        }

        try {
            $config = json_decode((string) file_get_contents($path), true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            return ['valid' => false, 'errors' => ['/: invalid JSON ('.$exception->getMessage().')'], 'warnings' => [], 'config' => null];
        }

        return $this->validate($config);
    }

    /**
     * @return array{valid: bool, errors: list<string>, warnings: list<string>, config: array<string, mixed>|null}
     */
    public function validate(mixed $config): array
    {
        $errors = (new Validator)->validate($config, $this->schema);
        $warnings = [];

        if (is_array($config)) {
            $this->secrets($config, '', $errors);

            if ($errors === []) {
                $this->crossRules($config, $errors, $warnings);
            }
        }

        return [
            'valid' => $errors === [],
            'errors' => array_values(array_unique($errors)),
            'warnings' => array_values(array_unique($warnings)),
            'config' => is_array($config) && ! array_is_list($config) ? $config : null,
        ];
    }

    /**
     * Secret references (env:NAME, file:/path) used by the configuration.
     *
     * @param  array<string, mixed>  $config
     * @return array<string, string> pointer => reference
     */
    public function references(array $config): array
    {
        $references = [];
        $walk = function (mixed $value, string $path) use (&$walk, &$references): void {
            if (is_string($value) && preg_match('/^(env:[A-Z][A-Z0-9_]*|file:\/.+)$/', $value)) {
                $references[$path] = $value;
            } elseif (is_array($value)) {
                foreach ($value as $key => $item) {
                    $walk($item, $path.'/'.$key);
                }
            }
        };
        $walk($config, '');

        return $references;
    }

    /**
     * References that cannot be resolved in this environment (variable not
     * defined, file missing or unreadable). Values are never returned.
     *
     * @param  array<string, mixed>  $config
     * @param  (callable(string): (string|false|null))|null  $env
     * @return list<string>
     */
    public function unresolved(array $config, ?callable $env = null): array
    {
        $env ??= fn (string $name) => $_ENV[$name] ?? $_SERVER[$name] ?? getenv($name);
        $missing = [];

        foreach ($this->references($config) as $pointer => $reference) {
            [$kind, $target] = explode(':', $reference, 2);
            $value = $kind === 'env' ? $env($target) : null;
            $resolved = $kind === 'env'
                ? is_string($value) && $value !== ''
                : is_file($target) && is_readable($target);

            if (! $resolved) {
                $missing[] = $kind === 'env'
                    ? "{$pointer}: the environment variable {$target} is not defined"
                    : "{$pointer}: the file {$target} does not exist or is not readable";
            }
        }

        return $missing;
    }

    /**
     * @param  array<mixed>  $value
     * @param  list<string>  $errors
     */
    private function secrets(array $value, string $path, array &$errors): void
    {
        foreach ($value as $key => $item) {
            $pointer = $path.'/'.$key;

            if (is_array($item)) {
                $this->secrets($item, $pointer, $errors);
            } elseif (is_string($item)) {
                foreach (self::SECRET_PATTERNS as $label => $pattern) {
                    if (preg_match($pattern, $item)) {
                        $errors[] = "{$pointer}: looks like a plain secret ({$label}); reference it with env:NAME or file:/path";

                        break;
                    }
                }
            }
        }
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  list<string>  $errors
     * @param  list<string>  $warnings
     */
    private function crossRules(array $config, array &$errors, array &$warnings): void
    {
        $features = (array) ($config['features'] ?? []);
        $ai = (array) ($config['ai'] ?? []);
        $rag = (array) ($config['rag'] ?? []);
        $connections = array_values(array_filter((array) ($ai['connections'] ?? []), 'is_array'));
        $refs = array_map(fn (array $connection) => (string) ($connection['ref'] ?? ''), $connections);

        foreach (array_unique(array_diff_assoc($refs, array_unique($refs))) as $duplicate) {
            $errors[] = "/ai/connections: the reference \"{$duplicate}\" is declared more than once";
        }

        foreach (['/ai/connectionRef' => $ai['connectionRef'] ?? null, '/ai/agent/microphone/connectionRef' => $ai['agent']['microphone']['connectionRef'] ?? null] as $pointer => $ref) {
            if (is_string($ref) && ! in_array($ref, $refs, true)) {
                $errors[] = "{$pointer}: \"{$ref}\" is not declared in /ai/connections";
            }
        }

        foreach ($connections as $index => $connection) {
            $driver = $connection['driver'] ?? null;

            if (in_array($driver, ['openai-compatible', 'azure'], true) && empty($connection['baseUrl'])) {
                $errors[] = "/ai/connections/{$index}/baseUrl: required for the {$driver} driver";
            }

            if (! in_array($driver, ['simulated', 'ollama'], true) && empty($connection['credentialRef'])) {
                $warnings[] = "/ai/connections/{$index}/credentialRef: no credential reference; the connection stays pending until a key is configured";
            }
        }

        if (($ai['agent']['microphone']['enabled'] ?? false) === true && empty($ai['agent']['microphone']['connectionRef'])) {
            $errors[] = '/ai/agent/microphone/connectionRef: the microphone requires a transcription connection';
        }

        if (($features['ai'] ?? false) === true) {
            if ($connections === []) {
                $warnings[] = '/ai/connections: no AI connection; the clearly labelled simulated provider will be used';
            }

            if (array_key_exists('agent', $ai) && ($ai['agent']['modelRef'] ?? null) === null) {
                $warnings[] = '/ai/agent/modelRef: pending selection; the agent is published once a model is chosen';
            }
        } elseif (isset($ai['agent']) || $connections !== []) {
            $warnings[] = '/features/ai: AI is disabled; the /ai section is kept but not applied';
        }

        $mode = $rag['mode'] ?? 'disabled';

        if ($mode !== 'disabled' && ($features['rag'] ?? false) !== true) {
            $errors[] = "/rag/mode: \"{$mode}\" requires /features/rag = true";
        }

        if (($features['rag'] ?? false) === true) {
            if ($mode === 'disabled') {
                $warnings[] = '/rag/mode: the knowledge feature is enabled but no mode is selected';
            }

            if (($features['ai'] ?? false) !== true) {
                $errors[] = '/features/rag: knowledge requires /features/ai = true (embeddings and grounded answers)';
            }
        }

        if (in_array($mode, ['external-postgres', 'external-api'], true) && empty($rag['connectionRef'])) {
            $errors[] = "/rag/connectionRef: required for the {$mode} mode";
        }

        if (in_array($mode, ['internal', 'external-postgres'], true) && empty($rag['embeddingProfileRef'])) {
            $errors[] = "/rag/embeddingProfileRef: an embedding profile is required for the {$mode} mode";
        }

        if (($config['database']['mode'] ?? null) === 'external' && empty($config['database']['ref'])) {
            $errors[] = '/database/ref: an external database requires a connection reference (env:NAME or file:/path)';
        }

        $transport = $config['mail']['transport'] ?? null;

        $variables = ['resend' => 'RESEND_API_KEY', 'postmark' => 'POSTMARK_API_KEY', 'mailgun' => 'MAILGUN_SECRET'];

        if (in_array($transport, ['smtp', 'resend', 'postmark', 'mailgun'], true)) {
            if (empty($config['mail']['credentialRef'])) {
                $warnings[] = $transport === 'smtp'
                    ? '/mail/credentialRef: smtp needs a reference to a URL like smtps://user:password@host:465; login codes cannot be sent until it is configured'
                    : "/mail/credentialRef: {$transport} without a reference; the key is read from {$variables[$transport]} in the .env and login codes cannot be sent without it";
            }

            if (empty($config['mail']['from'])) {
                $warnings[] = '/mail/from: no sender address'.($transport === 'resend' ? ' (Resend only sends from a verified domain or onboarding@resend.dev)' : '');
            }

            if ($transport === 'mailgun' && empty($config['mail']['domain'])) {
                $warnings[] = '/mail/domain: mailgun needs the sending domain (or MAILGUN_DOMAIN in the .env)';
            }
        }

        if (($features['postgrest'] ?? false) === true) {
            $warnings[] = '/features/postgrest: requires POSTGREST_JWT_SECRET (32+ characters) and the postgrest Compose profile; it stays private';
        }

        if (! isset($config['owner'])) {
            $warnings[] = '/owner: no owner; create one later with php artisan starter:owner';
        }
    }
}
