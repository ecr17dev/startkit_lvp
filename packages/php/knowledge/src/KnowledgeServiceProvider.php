<?php

namespace Starter\Knowledge;

use Illuminate\Support\ServiceProvider;

class KnowledgeServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap the module services.
     */
    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }
}
