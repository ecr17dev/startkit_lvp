<?php

namespace Starter\Ai;

use Illuminate\Support\ServiceProvider;

class AiServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap the module services.
     */
    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }
}
