<?php

namespace App\Actions\Knowledge;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Starter\Administration\Settings\SettingsManager;
use Starter\Knowledge\Enums\ConnectionKind;
use Starter\Knowledge\Enums\ConnectionStatus;
use Starter\Knowledge\Models\EmbeddingProfile;
use Starter\Knowledge\Models\RagConnection;
use Starter\Knowledge\Store\ExternalDatabases;

/**
 * External knowledge connections. Credentials are stored encrypted or as
 * `env:STARTER_RAG_SECRET_*` references and never shown again; a change of
 * address or credentials sends the connection back to `pending` until its
 * diagnostics pass again. Nothing is created on the external database
 * here: that only happens on an explicit activation.
 */
class SaveRagConnection
{
    public function __construct(protected ExternalDatabases $databases, protected SettingsManager $settings) {}

    /**
     * @return array<string, mixed>
     */
    public static function rules(ConnectionKind $kind, ?RagConnection $connection = null): array
    {
        $common = [
            'label' => ['required', 'string', 'max:120'],
            /** Stable identifier (lowercase, digits, - and _). */
            'name' => ['nullable', 'string', 'max:60', 'regex:/^[a-z0-9]+(?:[-_][a-z0-9]+)*$/', Rule::notIn(['internal']), Rule::unique('rag_connections', 'name')->ignore($connection?->id)],
            'config' => ['required', 'array'],
            'credentials' => ['nullable', 'array'],
        ];

        return $common + match ($kind) {
            ConnectionKind::Postgres => [
                'config.host' => ['required', 'string', 'max:255', 'regex:/^[A-Za-z0-9.\-:\[\]]+$/'],
                'config.port' => ['required', 'integer', 'between:1,65535'],
                'config.database' => ['required', 'string', 'max:120'],
                /** Schema of the knowledge tables (created on activation). */
                'config.schema' => ['nullable', 'string', 'max:63', 'regex:/^[a-z_][a-z0-9_]*$/'],
                'config.sslmode' => ['required', Rule::in(ExternalDatabases::SSL_MODES)],
                /** Path of the CA certificate on the server (verify-ca / verify-full). */
                'config.sslrootcert' => ['nullable', 'string', 'max:500'],
                'config.connect_timeout' => ['nullable', 'integer', 'between:1,60'],
                'config.statement_timeout_ms' => ['nullable', 'integer', 'between:1000,120000'],
                /** Only search: ingestion is managed by the owner system. */
                'config.read_only' => ['boolean'],
                /** User or env:STARTER_RAG_SECRET_* reference. */
                'credentials.username' => [$connection === null ? 'required' : 'nullable', 'string', 'max:500'],
                /** Password or env:STARTER_RAG_SECRET_* reference (empty keeps the stored one). */
                'credentials.password' => [$connection === null ? 'required' : 'nullable', 'string', 'max:500'],
            ],
            ConnectionKind::Api, ConnectionKind::Internal => [],
        };
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function handle(User $actor, array $input, ?RagConnection $connection = null): RagConnection
    {
        Gate::forUser($actor)->authorize('settings.update');

        $kind = $connection->kind ?? ConnectionKind::tryFrom((string) ($input['kind'] ?? ''));

        if ($kind === null || $kind === ConnectionKind::Internal) {
            throw ValidationException::withMessages(['kind' => __('Choose PostgreSQL or a retrieval API.')]);
        }

        /** @var array{label: string, name?: string|null, config: array<string, mixed>, credentials?: array<string, string|null>|null} $data */
        $data = Validator::make($input, self::rules($kind, $connection))->validate();

        $config = $this->config($kind, $data['config']);
        $credentials = $this->credentials($data['credentials'] ?? [], $connection);
        $changed = $connection === null
            || $config !== ($connection->config ?? [])
            || $credentials !== ($connection->credentials ?? []);

        $connection ??= new RagConnection(['kind' => $kind, 'status' => ConnectionStatus::Pending]);
        $connection->fill([
            'label' => $data['label'],
            'name' => $data['name'] ?? $connection->name ?? $this->name($data['label']),
            'config' => $config,
            'credentials' => $credentials,
        ]);

        if ($changed) {
            // A new address or credentials must pass the diagnostics again...
            $connection->forceFill(['status' => ConnectionStatus::Pending, 'capabilities' => null]);
        }

        $connection->save();
        $this->databases->forget($connection);

        audit('rag.connections.saved', $connection, ['label' => $connection->label, 'kind' => $kind->value, 'settings_changed' => $changed], $actor);

        return $connection;
    }

    public function delete(User $actor, RagConnection $connection): void
    {
        Gate::forUser($actor)->authorize('settings.update');

        $active = (int) $this->settings->get('rag.connection_id') === $connection->id && $this->settings->get('rag.mode', 'disabled') !== 'disabled';

        if ($connection->kind === ConnectionKind::Internal || $active) {
            throw ValidationException::withMessages(['connection' => __('The internal or the active connection cannot be deleted.')]);
        }

        if ($connection->collections()->exists() || EmbeddingProfile::query()->where('connection_id', $connection->id)->exists()) {
            throw ValidationException::withMessages(['connection' => __('Delete the collections and profiles of this connection first.')]);
        }

        $connection->delete();
        $this->databases->forget($connection);

        audit('rag.connections.deleted', null, ['label' => $connection->label, 'kind' => $connection->kind->value], $actor);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    protected function config(ConnectionKind $kind, array $input): array
    {
        return match ($kind) {
            ConnectionKind::Postgres => array_filter([
                'host' => (string) $input['host'],
                'port' => (int) $input['port'],
                'database' => (string) $input['database'],
                'schema' => filled($input['schema'] ?? null) ? (string) $input['schema'] : 'public',
                'sslmode' => (string) $input['sslmode'],
                'sslrootcert' => filled($input['sslrootcert'] ?? null) ? (string) $input['sslrootcert'] : null,
                'connect_timeout' => (int) ($input['connect_timeout'] ?? 5),
                'statement_timeout_ms' => (int) ($input['statement_timeout_ms'] ?? 15000),
                'read_only' => (bool) ($input['read_only'] ?? false),
            ], fn ($value) => $value !== null),
            default => [],
        };
    }

    /**
     * Merge the new credentials (empty keeps the stored value) and check
     * the environment references.
     *
     * @param  array<string, string|null>  $input
     * @return array<string, string>
     */
    protected function credentials(array $input, ?RagConnection $connection): array
    {
        $credentials = $connection->credentials ?? [];

        foreach ($input as $key => $value) {
            if (! is_string($value) || $value === '') {
                continue;
            }

            if (str_starts_with($value, 'env:') && ! ExternalDatabases::isAllowedReference($value)) {
                throw ValidationException::withMessages(["credentials.{$key}" => __('Only env:STARTER_RAG_SECRET_* variables can be referenced.')]);
            }

            $credentials[$key] = $value;
        }

        /** @var array<string, string> */
        return array_filter($credentials, fn ($value) => is_string($value) && $value !== '');
    }

    protected function name(string $label): string
    {
        $base = str($label)->slug('_')->limit(50, '')->toString() ?: 'connection';
        $name = $base;
        $suffix = 2;

        while ($name === 'internal' || RagConnection::query()->where('name', $name)->exists()) {
            $name = $base.'_'.$suffix++;
        }

        return $name;
    }
}
