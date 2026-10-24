<?php

namespace Starter\Administration;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\ServiceProvider;
use Starter\Administration\Audit\Auditor;
use Starter\Administration\Health\HealthChecker;
use Starter\Administration\Mail\MailTemplates;
use Starter\Administration\Models\AuditEvent;
use Starter\Administration\Settings\Branding;
use Starter\Administration\Settings\RuntimeConfiguration;
use Starter\Administration\Settings\SettingsManager;

class AdministrationServiceProvider extends ServiceProvider
{
    /**
     * Register the module services.
     */
    public function register(): void
    {
        $this->app->singleton(SettingsManager::class);
        $this->app->singleton(RuntimeConfiguration::class);
        $this->app->singleton(Branding::class);
        $this->app->singleton(HealthChecker::class);
        $this->app->singleton(MailTemplates::class);
        $this->app->scoped(Auditor::class);
    }

    /**
     * Bootstrap the module services.
     */
    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'starter-admin');
        $this->loadRoutesFrom(__DIR__.'/../routes/health.php');

        // The readiness check warns when the scheduler stops beating...
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $schedule->call(fn () => Cache::put(HealthChecker::SCHEDULER_HEARTBEAT_KEY, now()->toIso8601String(), 600))
                ->everyMinute()
                ->name('starter:scheduler-heartbeat')
                ->onOneServer()
                ->withoutOverlapping();

            $schedule->command('model:prune', ['--model' => [AuditEvent::class]])
                ->daily()
                ->name('starter:prune-audit-events')
                ->onOneServer();
        });

        $this->app->booted(fn () => $this->app->make(RuntimeConfiguration::class)->apply());
    }
}
