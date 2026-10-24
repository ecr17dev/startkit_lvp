<?php

namespace Starter\Ai\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Where a price comes from: a provider list (direct), a gateway list, a
 * custom agreement or a manual entry.
 *
 * @property int $id
 * @property string $key
 * @property string $name
 * @property string $kind
 * @property string|null $url
 * @property bool $is_active
 * @property CarbonInterface|null $last_synced_at
 */
class PriceSource extends Model
{
    public const MANUAL = 'manual';

    public const OPENROUTER = 'openrouter';

    public const SIMULATED = 'simulated';

    protected $table = 'ai_price_sources';

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
            'is_active' => 'boolean',
            'last_synced_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<ModelPrice, $this>
     */
    public function prices(): HasMany
    {
        return $this->hasMany(ModelPrice::class, 'price_source_id');
    }

    /**
     * @return HasMany<PriceSyncRun, $this>
     */
    public function syncRuns(): HasMany
    {
        return $this->hasMany(PriceSyncRun::class, 'price_source_id');
    }

    public static function manual(): self
    {
        return self::query()->firstOrCreate(['key' => self::MANUAL], [
            'name' => 'Manual entry',
            'kind' => 'manual',
        ]);
    }

    public static function simulated(): self
    {
        return self::query()->firstOrCreate(['key' => self::SIMULATED], [
            'name' => 'Simulated provider',
            'kind' => 'manual',
        ]);
    }

    public static function openRouter(): self
    {
        return self::query()->firstOrCreate(['key' => self::OPENROUTER], [
            'name' => 'OpenRouter models API',
            'kind' => 'gateway',
            'url' => 'https://openrouter.ai/api/v1/models',
        ]);
    }
}
