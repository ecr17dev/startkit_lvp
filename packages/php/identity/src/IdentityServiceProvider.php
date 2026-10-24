<?php

namespace Starter\Identity;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;
use Starter\Administration\Mail\MailTemplates;
use Starter\Identity\Authorization\Delegation;
use Starter\Identity\Authorization\PermissionSynchronizer;
use Starter\Identity\Console\CreateOwnerCommand;
use Starter\Identity\Console\SyncPermissionsCommand;
use Starter\Identity\Models\LoginChallenge;
use Starter\Identity\Notifications\IdentityMailTemplates;
use Starter\Identity\Otp\OtpBroker;

class IdentityServiceProvider extends ServiceProvider
{
    /**
     * Register the module services.
     */
    public function register(): void
    {
        $this->app->singleton(OtpBroker::class);
        $this->app->singleton(Delegation::class);
        $this->app->singleton(PermissionSynchronizer::class);
    }

    /**
     * Bootstrap the module services.
     */
    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        // Access emails editable in Admin → Mail → Templates...
        $this->callAfterResolving(MailTemplates::class, fn (MailTemplates $templates) => IdentityMailTemplates::register($templates));

        // Used and expired one-time codes are removed every hour...
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $schedule->command('model:prune', ['--model' => [LoginChallenge::class]])
                ->hourly()
                ->name('starter:prune-login-challenges')
                ->onOneServer();
        });

        if ($this->app->runningInConsole()) {
            $this->commands([
                CreateOwnerCommand::class,
                SyncPermissionsCommand::class,
            ]);
        }
    }
}
