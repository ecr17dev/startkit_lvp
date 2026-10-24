<?php

namespace Starter\Ai;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Laravel\Ai\AiManager;
use Starter\Ai\Agents\AgentLimiter;
use Starter\Ai\Agents\AgentManager;
use Starter\Ai\Agents\ToolRegistry;
use Starter\Ai\Budgets\BudgetManager;
use Starter\Ai\Catalog\CatalogManager;
use Starter\Ai\Catalog\ModelDiscovery;
use Starter\Ai\Catalog\ModelVerifier;
use Starter\Ai\Catalog\OpenRouterCatalog;
use Starter\Ai\Connections\ConnectionResolver;
use Starter\Ai\Connections\ConnectionTester;
use Starter\Ai\Console\MaintainLedgerCommand;
use Starter\Ai\Console\SyncPricesCommand;
use Starter\Ai\Currency\ExchangeRates;
use Starter\Ai\Ledger\ExecutionContext;
use Starter\Ai\Ledger\UsageLedger;
use Starter\Ai\Listeners\RecordSdkUsage;
use Starter\Ai\Models\Agent;
use Starter\Ai\Models\AgentVersion;
use Starter\Ai\Models\AiModel;
use Starter\Ai\Models\AiRequest;
use Starter\Ai\Models\Budget;
use Starter\Ai\Models\Connection;
use Starter\Ai\Pricing\CostCalculator;
use Starter\Ai\Pricing\CostEstimator;
use Starter\Ai\Pricing\PriceBook;
use Starter\Ai\Simulated\SimulatedProvider;

class AiServiceProvider extends ServiceProvider
{
    /**
     * Register the module services.
     */
    public function register(): void
    {
        // Per request/job state...
        $this->app->scoped(ExecutionContext::class);

        foreach ([ConnectionResolver::class, CostCalculator::class, CostEstimator::class, PriceBook::class, BudgetManager::class,
            UsageLedger::class, AiGateway::class, ModelDiscovery::class, OpenRouterCatalog::class, CatalogManager::class,
            ModelVerifier::class, ConnectionTester::class, ExchangeRates::class, AgentManager::class, AgentLimiter::class] as $service) {
            $this->app->scoped($service);
        }

        // Tools are registered by the application once per process...
        $this->app->singleton(ToolRegistry::class);
    }

    /**
     * Bootstrap the module services.
     */
    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        Event::subscribe(RecordSdkUsage::class);

        $this->callAfterResolving(AiManager::class, function (AiManager $manager) {
            $manager->extend(SimulatedProvider::DRIVER, fn ($app, array $config) => new SimulatedProvider($config, $app->make(Dispatcher::class)));
        });

        Relation::morphMap([
            'ai_connection' => Connection::class,
            'ai_model' => AiModel::class,
            'ai_request' => AiRequest::class,
            'ai_budget' => Budget::class,
            'ai_agent' => Agent::class,
            'ai_agent_version' => AgentVersion::class,
        ]);

        if ($this->app->runningInConsole()) {
            $this->commands([MaintainLedgerCommand::class, SyncPricesCommand::class]);
        }

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $schedule->command('starter:ai-maintain')->everyFiveMinutes()->name('starter:ai-maintain')->onOneServer()->withoutOverlapping();
            $schedule->command('starter:ai-sync-prices')->dailyAt('03:17')->name('starter:ai-sync-prices')->onOneServer()->withoutOverlapping();
        });
    }
}
