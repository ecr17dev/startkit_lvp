<?php

namespace Starter\Ai\Connections;

use Laravel\Ai\AiManager;
use Starter\Ai\Enums\Driver;
use Starter\Ai\Models\Connection;
use Starter\Ai\Simulated\SimulatedProvider;

/**
 * Turns a connection into an SDK provider built on demand for the current
 * process (AiManager::build). The shared "ai" configuration is never
 * mutated, so long lived workers never leak credentials between runs.
 */
class ConnectionResolver
{
    public const PREFIX = 'starter_c';

    public function __construct(protected AiManager $manager) {}

    /**
     * Build the provider and return the name to pass to the SDK.
     */
    public function providerName(Connection $connection, ?string $alias = null): string
    {
        // An alias builds another instance of the same connection, used as a
        // distinct entry of the SDK failover (retries, fallbacks)...
        $name = self::PREFIX.$connection->id.'_'.$connection->credentialsVersion().($alias !== null ? '_'.$alias : '');

        $this->manager->build(['name' => $name, ...$this->config($connection)]);

        return $name;
    }

    /**
     * Configuration understood by the SDK driver.
     *
     * @return array<string, mixed>
     */
    public function config(Connection $connection): array
    {
        $config = [
            'driver' => $connection->driver->value,
            'key' => $connection->credential('api_key') ?? '',
        ];

        if (filled($connection->base_url)) {
            $config['url'] = rtrim((string) $connection->base_url, '/');
        }

        return match ($connection->driver) {
            Driver::Bedrock => [
                ...$config,
                'key' => $connection->credential('api_key'),
                'region' => $connection->option('region', 'us-east-1'),
                'access_key_id' => $connection->credential('access_key_id'),
                'secret_access_key' => $connection->credential('secret_access_key'),
                'session_token' => $connection->credential('session_token'),
                'use_default_credential_provider' => $connection->credential('api_key') === null && $connection->credential('access_key_id') === null,
            ],
            Driver::Azure => [
                ...$config,
                'api_version' => $connection->option('api_version', '2025-04-01-preview'),
                'deployment' => $connection->option('deployment'),
            ],
            Driver::Simulated => ['driver' => SimulatedProvider::DRIVER, 'key' => ''],
            default => $config,
        };
    }

    /**
     * Get the connection id encoded in a provider name built by this class.
     */
    public static function connectionId(?string $providerName): ?int
    {
        if ($providerName !== null && preg_match('/^'.self::PREFIX.'(\d+)_/', $providerName, $matches)) {
            return (int) $matches[1];
        }

        return null;
    }
}
