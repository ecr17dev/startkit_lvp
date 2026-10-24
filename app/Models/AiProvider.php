<?php

namespace App\Models;

use App\Ai\ProviderDriver;
use App\Ai\ProviderRegistry;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * @property int $id
 * @property string $name
 * @property string $label
 * @property ProviderDriver $driver
 * @property string|null $api_key
 * @property string|null $base_url
 * @property array<string, mixed>|null $options
 * @property string|null $default_model
 * @property bool $is_active
 * @property bool $is_default
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, AiModel> $models
 */
#[Fillable(['name', 'label', 'driver', 'api_key', 'base_url', 'options', 'default_model', 'is_active', 'is_default'])]
#[Hidden(['api_key', 'options'])]
class AiProvider extends Model
{
    /**
     * Option keys that hold secrets and must never reach the browser.
     *
     * @var list<string>
     */
    public const SECRET_OPTIONS = ['secret_access_key', 'session_token'];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'driver' => ProviderDriver::class,
            'api_key' => 'encrypted',
            'options' => 'encrypted:array',
            'is_active' => 'boolean',
            'is_default' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saved(fn () => ProviderRegistry::flush());
        static::deleted(fn () => ProviderRegistry::flush());
    }

    /**
     * Get the priced models of the provider.
     *
     * @return HasMany<AiModel, $this>
     */
    public function models(): HasMany
    {
        return $this->hasMany(AiModel::class);
    }

    /**
     * Scope the query to active providers.
     *
     * @param  Builder<AiProvider>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * Get an option value.
     */
    public function option(string $key, mixed $default = null): mixed
    {
        return $this->options[$key] ?? $default;
    }

    /**
     * Build the configuration array understood by the Laravel AI SDK.
     *
     * @return array<string, mixed>
     */
    public function toAiConfig(): array
    {
        $config = [
            'driver' => $this->driver->value,
            'key' => $this->api_key ?? '',
        ];

        if (filled($this->base_url)) {
            $config['url'] = $this->base_url;
        }

        if (filled($this->default_model)) {
            $config['models']['text']['default'] = $this->default_model;
        }

        return match ($this->driver) {
            ProviderDriver::Bedrock => [
                ...$config,
                'key' => $this->api_key ?: null,
                'region' => $this->option('region', 'us-east-1'),
                'access_key_id' => $this->option('access_key_id'),
                'secret_access_key' => $this->option('secret_access_key'),
                'session_token' => $this->option('session_token'),
                'use_default_credential_provider' => blank($this->api_key) && blank($this->option('access_key_id')),
            ],
            ProviderDriver::Azure => [
                ...$config,
                'api_version' => $this->option('api_version', '2025-04-01-preview'),
                'deployment' => $this->option('deployment', $this->default_model),
            ],
            default => $config,
        };
    }

    /**
     * Get a masked version of the API key that is safe to display.
     */
    public function maskedApiKey(): ?string
    {
        if (blank($this->api_key)) {
            return null;
        }

        return str_repeat('•', 8).substr((string) $this->api_key, -4);
    }
}
