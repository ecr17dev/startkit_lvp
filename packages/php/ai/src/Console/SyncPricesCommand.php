<?php

namespace Starter\Ai\Console;

use Illuminate\Console\Command;
use Starter\Ai\Catalog\CatalogManager;
use Starter\Ai\Currency\ExchangeRates;
use Throwable;

class SyncPricesCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'starter:ai-prices-sync {--rates : Also fetch the USD/CLP exchange rate}';

    /**
     * @var list<string>
     */
    protected $aliases = ['starter:ai-sync-prices'];

    /**
     * @var string
     */
    protected $description = 'Sync gateway prices from structured catalogs (OpenRouter) and optional exchange rates';

    public function handle(CatalogManager $catalog, ExchangeRates $rates): int
    {
        $run = $catalog->syncOpenRouterPrices();

        $this->components->info("Price sync {$run->status}: ".json_encode($run->stats));

        if ($run->error !== null) {
            $this->components->error($run->error);
        }

        if ($this->option('rates')) {
            try {
                $rate = $rates->fetchClp();
                $this->components->info("USD/CLP {$rate->rate} ({$rate->rate_date->toDateString()}, {$rate->source})");
            } catch (Throwable $exception) {
                $this->components->warn('Exchange rate not updated: '.$exception->getMessage());
            }
        }

        return $run->status === 'succeeded' ? self::SUCCESS : self::FAILURE;
    }
}
