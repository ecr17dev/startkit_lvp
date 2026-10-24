<?php

namespace Starter\Knowledge\Store;

use Illuminate\Support\Env;
use Illuminate\Support\Facades\DB;
use PDO;
use Starter\Knowledge\Models\RagConnection;

/**
 * Laravel database connections (`rag_{id}`) for the external PostgreSQL
 * connections, built at runtime from the stored settings. Credentials are
 * stored encrypted or referenced as `env:STARTER_RAG_SECRET_*`; they never
 * reach the panel, logs or exported JSON. A connection is re-registered
 * (and its PDO purged) whenever its settings change.
 */
class ExternalDatabases
{
    public const SSL_MODES = ['disable', 'allow', 'prefer', 'require', 'verify-ca', 'verify-full'];

    /**
     * @var array<string, string> connection name => settings fingerprint
     */
    protected array $registered = [];

    public function register(RagConnection $connection): string
    {
        $name = 'rag_'.$connection->id;
        $config = $this->config($connection);
        $fingerprint = hash('sha256', serialize($config));

        if (($this->registered[$name] ?? null) !== $fingerprint) {
            config(["database.connections.{$name}" => $config]);
            DB::purge($name);
            $this->registered[$name] = $fingerprint;
        }

        return $name;
    }

    /**
     * Forget a connection (settings changed or connection deleted).
     */
    public function forget(RagConnection $connection): void
    {
        $name = 'rag_'.$connection->id;

        DB::purge($name);
        unset($this->registered[$name]);
    }

    /**
     * @return array<string, mixed>
     */
    public function config(RagConnection $connection): array
    {
        $settings = $connection->config ?? [];
        $credentials = $connection->credentials ?? [];

        $serverOptions = array_filter([
            'statement_timeout' => max(1000, (int) ($settings['statement_timeout_ms'] ?? 15000)),
            // Read only connections cannot write even by mistake...
            'default_transaction_read_only' => ($settings['read_only'] ?? false) ? 'on' : null,
        ], fn ($value) => $value !== null);

        return array_filter([
            'driver' => 'pgsql',
            'host' => (string) ($settings['host'] ?? ''),
            'port' => (int) ($settings['port'] ?? 5432),
            'database' => (string) ($settings['database'] ?? ''),
            'username' => self::secret($credentials['username'] ?? null),
            'password' => self::secret($credentials['password'] ?? null),
            'charset' => 'utf8',
            'prefix' => '',
            'prefix_indexes' => true,
            // The knowledge schema first; public keeps the vector type visible...
            'search_path' => self::searchPath((string) ($settings['schema'] ?? 'public')),
            'sslmode' => in_array($settings['sslmode'] ?? null, self::SSL_MODES, true) ? $settings['sslmode'] : 'prefer',
            'sslrootcert' => filled($settings['sslrootcert'] ?? null) ? (string) $settings['sslrootcert'] : null,
            'application_name' => mb_substr(config('app.name').' knowledge', 0, 60),
            'server_options' => $serverOptions,
            'options' => [PDO::ATTR_TIMEOUT => max(1, min(60, (int) ($settings['connect_timeout'] ?? 5)))],
        ], fn ($value) => $value !== null);
    }

    public static function searchPath(string $schema): string
    {
        return $schema === '' || $schema === 'public' ? 'public' : $schema.', public';
    }

    /**
     * A stored value or an allowed environment reference.
     */
    public static function secret(mixed $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        if (str_starts_with($value, 'env:')) {
            if (! self::isAllowedReference($value)) {
                return null;
            }

            $resolved = Env::get(substr($value, 4));

            return is_string($resolved) && $resolved !== '' ? $resolved : null;
        }

        return $value;
    }

    /**
     * Only `STARTER_RAG_SECRET_*` variables may be referenced, never the
     * application secrets (APP_KEY, DB_*...).
     */
    public static function isAllowedReference(string $value): bool
    {
        return (bool) preg_match('/^env:STARTER_RAG_SECRET_[A-Z0-9_]{1,80}$/', $value);
    }
}
