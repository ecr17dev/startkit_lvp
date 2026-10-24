<?php

namespace App\Models;

use App\Ai\ProviderRegistry;
use App\Ai\Usage;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $ai_provider_id
 * @property string $model
 * @property string|null $label
 * @property string|null $input_price
 * @property string|null $output_price
 * @property string|null $cache_read_price
 * @property string|null $cache_write_price
 * @property int|null $context_window
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read AiProvider $provider
 */
#[Fillable(['model', 'label', 'input_price', 'output_price', 'cache_read_price', 'cache_write_price', 'context_window', 'is_active'])]
class AiModel extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'input_price' => 'decimal:6',
            'output_price' => 'decimal:6',
            'cache_read_price' => 'decimal:6',
            'cache_write_price' => 'decimal:6',
            'context_window' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saved(fn () => ProviderRegistry::flush());
        static::deleted(fn () => ProviderRegistry::flush());
    }

    /**
     * Get the provider the model belongs to.
     *
     * @return BelongsTo<AiProvider, $this>
     */
    public function provider(): BelongsTo
    {
        return $this->belongsTo(AiProvider::class, 'ai_provider_id');
    }

    /**
     * Determine whether the model has a price configured.
     */
    public function isPriced(): bool
    {
        return $this->input_price !== null && $this->output_price !== null;
    }

    /**
     * Calculate the cost in USD of the given usage.
     *
     * Cached tokens fall back to the regular input price when the
     * provider has no dedicated cache price configured.
     */
    public function costFor(Usage $usage): float
    {
        $input = (float) $this->input_price;
        $cacheRead = $this->cache_read_price !== null ? (float) $this->cache_read_price : $input;
        $cacheWrite = $this->cache_write_price !== null ? (float) $this->cache_write_price : $input;

        return (
            $usage->uncachedInputTokens() * $input
            + $usage->cacheReadTokens * $cacheRead
            + $usage->cacheWriteTokens * $cacheWrite
            + $usage->outputTokens * (float) $this->output_price
        ) / 1_000_000;
    }
}
