<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Starter\Ai\Agents\AgentManager;
use Starter\Ai\Models\Agent;
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
    public function run(Knowledge $knowledge, RagJobs $jobs, AgentManager $agents): void
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
            $this->agent($agents, Collection::query()->where('slug', 'documentacion')->first());

            return;
        }

        $profile = EmbeddingProfile::query()->firstOrCreate(['connection_id' => $connection->id, 'name' => 'Simulado · 1024d'], [
            'model_id' => $model->id,
            'provider' => 'simulated',
            'model' => $model->model,
            'dimensions' => 1024,
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

        $this->agent($agents, $collection);
    }

    /**
     * Agent that answers from the documentation with citations.
     */
    protected function agent(AgentManager $agents, ?Collection $collection): void
    {
        if ($collection === null || Agent::query()->where('slug', 'documentacion')->exists()) {
            return;
        }

        $agent = $agents->create(['slug' => 'documentacion', 'name' => 'Experto en documentación', 'description' => 'Responde con evidencia de la documentación del proyecto y cita sus fuentes.'], [
            'identity' => ['avatar' => '📚', 'greeting' => 'Pregúntame sobre la documentación del proyecto.', 'language' => 'auto', 'tone' => 'neutral'],
            'instructions' => [
                'system_prompt' => 'Eres el experto en la documentación de {{app_name}}. Responde en el idioma del usuario, de forma breve y precisa.',
                'variables' => ['app_name'],
            ],
            'rag' => ['mode' => 'always', 'collections' => [$collection->id], 'top_k' => 4, 'max_context_tokens' => 2500, 'evidence' => 'required', 'min_hits' => 1, 'method' => null, 'min_score' => 0.15],
        ]);

        $agents->publish($agent);
    }
}
