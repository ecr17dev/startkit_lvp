<?php

namespace Starter\Ai\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Starter\Ai\Catalog\CatalogManager;

/**
 * Sync the gateway prices (OpenRouter models API) of the catalog.
 */
class SyncGatewayPrices implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public function __construct(public ?int $triggeredBy = null) {}

    public function handle(CatalogManager $catalog): void
    {
        $catalog->syncOpenRouterPrices($this->triggeredBy);
    }
}
