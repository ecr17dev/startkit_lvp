<?php

namespace Starter\Console;

use Illuminate\Support\ServiceProvider;
use Starter\Console\Commands\DoctorCommand;
use Starter\Console\Commands\HealthCommand;
use Starter\Console\Commands\ManifestCommand;

class ConsoleServiceProvider extends ServiceProvider
{
    /**
     * Register the starter Artisan commands.
     */
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                DoctorCommand::class,
                HealthCommand::class,
                ManifestCommand::class,
            ]);
        }
    }
}
