<?php

namespace Starter\Console;

use Illuminate\Support\ServiceProvider;
use Starter\Console\Commands\ApiDocsCommand;
use Starter\Console\Commands\ConfigValidateCommand;
use Starter\Console\Commands\ContractsCheckCommand;
use Starter\Console\Commands\DoctorCommand;
use Starter\Console\Commands\HealthCommand;
use Starter\Console\Commands\MakeModuleCommand;
use Starter\Console\Commands\ManifestCommand;
use Starter\Console\Commands\VerifyCommand;

class ConsoleServiceProvider extends ServiceProvider
{
    /**
     * Register the starter Artisan commands.
     */
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                ApiDocsCommand::class,
                ConfigValidateCommand::class,
                ContractsCheckCommand::class,
                DoctorCommand::class,
                HealthCommand::class,
                MakeModuleCommand::class,
                ManifestCommand::class,
                VerifyCommand::class,
            ]);
        }
    }
}
