<?php

namespace Starter\Ai\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Starter\Ai\Enums\Capability;
use Starter\Ai\Enums\CapabilityStatus;
use Starter\Ai\Enums\ModelStatus;

/**
 * A model of the catalog, reachable through one connection (billing).
 *
 * @property int $id
 * @property int $connection_id
 * @property string $model
 * @property string|null $label
 * @property string|null $provider
 * @property list<string>|null $functions
 * @property list<string>|null $input_modalities
 * @property list<string>|null $output_modalities
 * @property int|null $context_window
 * @property int|null $max_output_tokens
 * @property ModelStatus $status
 * @property string $source
 * @property string|null $source_url
 * @property CarbonInterface|null $discovered_at
 * @property CarbonInterface|null $verified_at
 * @property string|null $last_error
 * @property bool $is_enabled
 * @property bool $is_default
 * @property array<string, mixed>|null $metadata
 * @property CarbonInterface $created_at
 * @property CarbonInterface $updated_at
 * @property-read Connection $connection
 */
class AiModel extends Model
{
    protected $table = 'ai_models';

    /**
     * @var list<string>
     */
    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'functions' => 'array',
            'input_modalities' => 'array',
            'output_modalities' => 'array',
            'context_window' => 'integer',
            'max_output_tokens' => 'integer',
            'status' => ModelStatus::class,
            'discovered_at' => 'datetime',
            'verified_at' => 'datetime',
            'is_enabled' => 'boolean',
            'is_default' => 'boolean',
            'metadata' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Connection, $this>
     */
    public function connection(): BelongsTo
    {
        return $this->belongsTo(Connection::class, 'connection_id');
    }

    /**
     * @return HasMany<ModelCapability, $this>
     */
    public function capabilities(): HasMany
    {
        return $this->hasMany(ModelCapability::class, 'model_id');
    }

    /**
     * @return HasMany<ModelPrice, $this>
     */
    public function prices(): HasMany
    {
        return $this->hasMany(ModelPrice::class, 'model_id');
    }

    /**
     * Models that can be selected: enabled, from an active connection and
     * not known to be unavailable.
     *
     * @param  Builder<self>  $query
     */
    public function scopeUsable(Builder $query): void
    {
        $query->where('is_enabled', true)
            ->whereNotIn('status', [ModelStatus::Unavailable->value, ModelStatus::Deprecated->value])
            ->whereHas('connection', fn ($query) => $query->where('is_active', true));
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeForFunction(Builder $query, string $function): void
    {
        $query->where(fn ($query) => $query->whereJsonContains('functions', $function)->orWhereNull('functions'));
    }

    public function supportsFunction(string $function): bool
    {
        return $this->functions === null || in_array($function, $this->functions, true);
    }

    public function capabilityStatus(Capability $capability): CapabilityStatus
    {
        $record = $this->relationLoaded('capabilities')
            ? $this->capabilities->firstWhere('capability', $capability->value)
            : $this->capabilities()->where('capability', $capability->value)->first();

        return $record->status ?? CapabilityStatus::Unknown;
    }

    public function displayName(): string
    {
        return $this->label ?: $this->model;
    }
}
