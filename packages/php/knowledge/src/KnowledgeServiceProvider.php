<?php

namespace Starter\Knowledge;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\ServiceProvider;
use Starter\Knowledge\Console\DiagnoseCommand;
use Starter\Knowledge\Console\MaintainCommand;
use Starter\Knowledge\Embeddings\Embedder;
use Starter\Knowledge\Ingestion\Chunker;
use Starter\Knowledge\Ingestion\Fetch\HostResolver;
use Starter\Knowledge\Ingestion\Fetch\SafeFetcher;
use Starter\Knowledge\Ingestion\Normalizer;
use Starter\Knowledge\Ingestion\Parsers\ParserRegistry;
use Starter\Knowledge\Ingestion\Sources\AdapterRegistry;
use Starter\Knowledge\Ingestion\Sources\SourceDrivers;
use Starter\Knowledge\Models\Collection;
use Starter\Knowledge\Models\Document;
use Starter\Knowledge\Models\EmbeddingProfile;
use Starter\Knowledge\Models\RagConnection;
use Starter\Knowledge\Models\RetrievalRun;
use Starter\Knowledge\Models\Source;
use Starter\Knowledge\Pipeline\Completion;
use Starter\Knowledge\Pipeline\Deletions;
use Starter\Knowledge\Pipeline\DocumentIndexer;
use Starter\Knowledge\Pipeline\DocumentIngestor;
use Starter\Knowledge\Pipeline\Estimator;
use Starter\Knowledge\Pipeline\IndexVersions;
use Starter\Knowledge\Pipeline\IngestionBudget;
use Starter\Knowledge\Pipeline\JobRunner;
use Starter\Knowledge\Pipeline\Purger;
use Starter\Knowledge\Pipeline\RagJobs;
use Starter\Knowledge\Pipeline\Reindexer;
use Starter\Knowledge\Pipeline\SourceSync;
use Starter\Knowledge\Retrieval\Retriever;
use Starter\Knowledge\Store\Provisioner;
use Starter\Knowledge\Store\VectorStore;

class KnowledgeServiceProvider extends ServiceProvider
{
    /**
     * Register the module services.
     */
    public function register(): void
    {
        foreach ([Knowledge::class, Provisioner::class, VectorStore::class, Embedder::class, SafeFetcher::class, HostResolver::class,
            Normalizer::class, Chunker::class, SourceDrivers::class, DocumentIngestor::class, IndexVersions::class, IngestionBudget::class,
            Estimator::class, RagJobs::class, SourceSync::class, DocumentIndexer::class, Purger::class, Reindexer::class,
            Completion::class, Deletions::class, JobRunner::class, Retriever::class] as $service) {
            $this->app->scoped($service);
        }

        // Registered once per process by the application...
        $this->app->singleton(ParserRegistry::class);
        $this->app->singleton(AdapterRegistry::class);
    }

    /**
     * Bootstrap the module services.
     */
    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        Relation::morphMap([
            'rag_connection' => RagConnection::class,
            'rag_profile' => EmbeddingProfile::class,
            'rag_collection' => Collection::class,
            'rag_source' => Source::class,
            'rag_document' => Document::class,
            'rag_retrieval_run' => RetrievalRun::class,
        ]);

        if ($this->app->runningInConsole()) {
            $this->commands([MaintainCommand::class, DiagnoseCommand::class]);
        }

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $schedule->command('starter:rag-maintain')->everyMinute()->name('starter:rag-maintain')->onOneServer()->withoutOverlapping();
            $schedule->command('model:prune', ['--model' => [RetrievalRun::class]])
                ->dailyAt('04:23')
                ->name('starter:prune-retrieval-runs')
                ->onOneServer()
                ->withoutOverlapping();
        });
    }
}
