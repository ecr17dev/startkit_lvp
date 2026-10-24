<?php

namespace Starter\Identity;

use Illuminate\Support\ServiceProvider;

class IdentityServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap the module services.
     */
    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }
}
