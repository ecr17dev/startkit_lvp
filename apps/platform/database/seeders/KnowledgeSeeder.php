<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Starter\Ai\Models\AiModel;
use Starter\Knowledge\Enums\JobType;
use Starter\Knowledge\Enums\Metric;
use Starter\Knowledge\Enums\RagMode;
use Starter\Knowledge\Enums\SourceStatus;
use Starter\Knowledge\Enums\SourceType;
use Starter\Knowledge\Knowledge;
use Starter\Knowledge\Models\Collection;
use Starter\Knowledge\Models\EmbeddingProfile;
use Starter\Knowledge\Pipeline\RagJobs;

class KnowledgeSeeder extends Seeder
{
    /**
     * Local demo of the knowledge module (only with STARTER_FEATURE_RAG and
     * the simulated provider): activates internal RAG if the diagnostics
     * pass, creates a simulated embedding profile and a collection with the
     * project documentation. Production installations activate RAG from
     * Admin → Knowledge with a real embeddings model.
     */
    public function run(Knowledge $knowledge, RagJobs $jobs): void
    {
        if (! config('starter.features.rag') || ! config('starter.ai.simulated')) {
            return;
        }

        $model = AiModel::query()->where('model', 'simulated-embedding')->first();

        if ($model === null) {
            return;
        }

        if (! $knowledge->available()) {
            $report = $knowledge->activate(RagMode::Internal);

            if (! $report->passed()) {
                $this->command?->warn('Knowledge (RAG) was not activated: run `php artisan starter:rag-diagnose`.');

                return;
            }
        }

        $connection = $knowledge->connection();

        if ($connection === null || Collection::query()->exists()) {
            return;
        }

        $profile = EmbeddingProfile::query()->firstOrCreate(['connection_id' => $connection->id, 'name' => 'Simulado · 256d'], [
            'model_id' => $model->id,
            'provider' => 'simulated',
            'model' => $model->model,
            'dimensions' => 256,
            'metric' => Metric::Cosine,
            'normalize' => true,
            'batch_size' => 32,
        ]);

        $knowledge->provisionProfile($profile);

        $collection = Collection::query()->create([
            'slug' => 'documentacion',
            'name' => 'Documentación del proyecto',
            'description' => 'Guías del starter kit (docs/*.md) indexadas con el adaptador de contenido interno.',
            'connection_id' => $connection->id,
            'embedding_profile_id' => $profile->id,
            'language' => 'es',
            'chunking' => Collection::defaultChunking(),
            'search' => Collection::defaultSearch(),
            'acl' => ['everyone'],
        ]);

        $source = $collection->sources()->create([
            'type' => SourceType::Adapter,
            'name' => 'docs/*.md',
            'config' => ['adapter' => 'project-docs'],
            'status' => SourceStatus::Active,
        ]);

        $jobs->create(JobType::SyncSource, ['collection_id' => $collection->id, 'source_id' => $source->id, 'trigger' => 'seed']);
    }
}
