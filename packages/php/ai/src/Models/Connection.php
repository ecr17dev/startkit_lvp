<?php

namespace Starter\Ai\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Env;
use Starter\Ai\Enums\Driver;

/**
 * A credential + endpoint of a provider. Several connections may use the
 * same provider (projects, accounts, gateways).
 *
 * @property int $id
 * @property string $name
 * @property string $label
 * @property Driver $driver
 * @property string|null $base_url
 * @property array<string, string|null>|null $credentials
 * @property string|null $secret_ref
 * @property array<string, mixed>|null $options
 * @property bool $is_active
 * @property bool $is_default
 * @property CarbonInterface|null $credentials_rotated_at
 * @property CarbonInterface|null $last_tested_at
 * @property string|null $last_test_status
 * @property string|null $last_test_message
 * @property int|null $created_by
 * @property CarbonInterface $created_at
 * @property CarbonInterface $updated_at
 */
class Connection extends Model
{
    protected $table = 'ai_provider_connections';

    /**
     * @var list<string>
     */
    protected $guarded = ['id'];

    /**
     * Credentials never leave the server.
     *
     * @var list<string>
     */
    protected $hidden = ['credentials'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'driver' => Driver::class,
            'credentials' => 'encrypted:array',
            'options' => 'array',
            'is_active' => 'boolean',
            'is_default' => 'boolean',
            'credentials_rotated_at' => 'datetime',
            'last_tested_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<AiModel, $this>
     */
    public function models(): HasMany
    {
        return $this->hasMany(AiModel::class, 'connection_id');
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * Get a credential, resolving the secret reference for the API key.
     */
    public function credential(string $key): ?string
    {
        if ($key === 'api_key' && filled($this->secret_ref)) {
            $value = self::resolveSecretRef((string) $this->secret_ref);

            return is_string($value) && $value !== '' ? $value : null;
        }

        $value = $this->credentials[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * Masked credential safe to display (last four characters).
     */
    public function maskedCredential(string $key): ?string
    {
        if ($key === 'api_key' && filled($this->secret_ref)) {
            return (string) $this->secret_ref;
        }

        $value = $this->credentials[$key] ?? null;

        return is_string($value) && $value !== '' ? str_repeat('•', 8).substr($value, -4) : null;
    }

    public function option(string $key, mixed $default = null): mixed
    {
        return $this->options[$key] ?? $default;
    }

    /**
     * Endpoint used for the connection.
     */
    public function endpoint(): ?string
    {
        return filled($this->base_url) ? rtrim((string) $this->base_url, '/') : ($this->driver->defaultUrl() !== null ? rtrim($this->driver->defaultUrl(), '/') : null);
    }

    /**
     * Version of the credentials: changes when they are rotated so cached
     * provider instances are rebuilt.
     */
    public function credentialsVersion(): string
    {
        return substr(hash('sha256', $this->id.'|'.($this->credentials_rotated_at?->getTimestamp() ?? 0).'|'.$this->updated_at->getTimestamp()), 0, 10);
    }

    /**
     * Determine whether a secret reference is allowed. Only AI provider keys
     * may be referenced, never application secrets (APP_KEY, DB_*...).
     */
    public static function isAllowedSecretRef(string $ref): bool
    {
        if (! preg_match('/^env:([A-Z][A-Z0-9_]{1,100})$/', $ref, $matches)) {
            return false;
        }

        $name = $matches[1];
        $allowed = (array) config('starter.ai.secret_refs', []);

        return in_array($name, $allowed, true) || str_starts_with($name, 'STARTER_AI_SECRET_');
    }

    public static function resolveSecretRef(string $ref): mixed
    {
        if (! self::isAllowedSecretRef($ref)) {
            return null;
        }

        return Env::get(substr($ref, 4));
    }
}
