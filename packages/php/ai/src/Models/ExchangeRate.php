<?php

namespace Starter\Ai\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * Rate used only to display amounts in another currency. The ledger
 * always keeps the original USD amounts.
 *
 * @property int $id
 * @property string $base
 * @property string $quote
 * @property string $rate
 * @property CarbonInterface $rate_date
 * @property string $source
 * @property CarbonInterface $fetched_at
 */
class ExchangeRate extends Model
{
    protected $table = 'ai_exchange_rates';

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
            'rate' => 'decimal:8',
            'rate_date' => 'date',
            'fetched_at' => 'datetime',
        ];
    }

    public static function latestFor(string $quote, string $base = 'USD'): ?self
    {
        return self::query()->where('base', $base)->where('quote', $quote)->latest('rate_date')->latest('id')->first();
    }
}
