<?php

namespace Tests\Feature\Ai;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Inertia\Support\SessionKey;
use Laravel\Sanctum\Sanctum;
use Starter\Ai\Agents\LabAgent;
use Starter\Ai\Agents\RecommendationAgent;
use Starter\Ai\Catalog\CatalogManager;
use Starter\Ai\Enums\CapabilityStatus;
use Starter\Ai\Enums\Feature;
use Starter\Ai\Jobs\RunLabTasks;
use Starter\Ai\Models\AiModel;
use Starter\Ai\Models\AiRequest;
use Starter\Ai\Models\LabResult;
use Starter\Ai\Models\LabRun;
use Tests\Concerns\CreatesAiCatalog;
use Tests\TestCase;

class RecommenderAndLabTest extends TestCase
{
    use CreatesAiCatalog, RefreshDatabase;

    protected function flash(TestResponse $response, string $key): mixed
    {
        return data_get(app('session.store')->get(SessionKey::FLASH_DATA, []), $key);
    }

    /**
     * @return array{cheap: AiModel, premium: AiModel, unpriced: AiModel, plain: AiModel}
     */
    protected function catalog(): array
    {
        $connection = $this->aiConnection();
        $catalog = app(CatalogManager::class);

        $cheap = $this->aiModel(['input' => '0.2', 'output' => '0.8'], ['model' => 'cheap-model', 'context_window' => 128000], $connection);
        $premium = $this->aiModel(['input' => '3', 'output' => '15'], ['model' => 'premium-model', 'is_default' => false, 'context_window' => 200000], $connection);
        $unpriced = $this->aiModel([], ['model' => 'unpriced-model', 'is_default' => false], $connection);
        $plain = $this->aiModel(['input' => '0.1', 'output' => '0.1'], ['model' => 'plain-model', 'is_default' => false], $connection);

        foreach ([$cheap, $premium, $unpriced] as $model) {
            $catalog->setCapability($model, 'tools', CapabilityStatus::Supported, 'manual');
        }

        return compact('cheap', 'premium', 'unpriced', 'plain');
    }

    public function test_candidates_are_filtered_by_verifiable_requirements(): void
    {
        ['cheap' => $cheap, 'premium' => $premium] = $this->catalog();

        $response = $this->actingAs($this->userWithRole('admin'))
            ->post(route('admin.ai.recommender.store'), [
                'use_case' => 'Atención de clientes con herramientas',
                'capabilities' => ['tools'],
                'max_input_price' => 5,
            ])
            ->assertSessionHasNoErrors()
            ->assertInertiaFlash('recommendation.source', 'deterministic')
            ->assertInertiaFlash('recommendation.recommendations.0.id', $cheap->id);

        // Without tools or above the price (or with an unknown price) a model is not a candidate...
        $this->assertSame([$cheap->id, $premium->id], collect($this->flash($response, 'recommendation.candidates'))->pluck('id')->all());

        // Without an evaluator nothing is billed...
        $this->assertSame(0, AiRequest::query()->count());
    }

    public function test_the_evaluator_only_recommends_candidates_and_its_cost_is_recorded(): void
    {
        ['cheap' => $cheap, 'premium' => $premium] = $this->catalog();
        $evaluator = AiModel::query()->where('model', 'plain-model')->sole();

        RecommendationAgent::fake([[
            'recommendations' => [
                ['model_id' => $premium->id, 'reasons' => 'Mejor razonamiento para casos complejos.'],
                ['model_id' => 999999, 'reasons' => 'Un modelo inventado'],
            ],
            'summary' => 'El premium conviene para conversaciones largas.',
        ]]);

        $response = $this->actingAs($this->userWithRole('admin'))
            ->post(route('admin.ai.recommender.store'), [
                'use_case' => 'Conversaciones largas con herramientas',
                'capabilities' => ['tools'],
                'evaluator_model_id' => $evaluator->id,
            ])
            ->assertInertiaFlash('recommendation.source', 'evaluator')
            ->assertInertiaFlash('recommendation.evaluator.valid', true);

        // The invented id is discarded...
        $this->assertSame([$premium->id], collect($this->flash($response, 'recommendation.recommendations'))->pluck('id')->all());

        $this->assertSame(1, AiRequest::query()->where('feature', Feature::Recommendation->value)->count());
        $this->assertNotNull($cheap);
    }

    public function test_an_invalid_evaluator_answer_falls_back_to_the_deterministic_ranking(): void
    {
        ['cheap' => $cheap] = $this->catalog();
        $evaluator = AiModel::query()->where('model', 'plain-model')->sole();

        RecommendationAgent::fake([['recommendations' => [['model_id' => 424242, 'reasons' => 'inventado']], 'summary' => 'x']]);

        $this->actingAs($this->userWithRole('admin'))
            ->post(route('admin.ai.recommender.store'), ['use_case' => 'Resúmenes', 'capabilities' => ['tools'], 'evaluator_model_id' => $evaluator->id])
            ->assertInertiaFlash('recommendation.source', 'deterministic')
            ->assertInertiaFlash('recommendation.evaluator.valid', false)
            ->assertInertiaFlash('recommendation.recommendations.0.id', $cheap->id);

        $this->assertSame(1, AiRequest::query()->where('feature', Feature::Recommendation->value)->count());
    }

    public function test_lab_runs_compare_models_with_latency_tokens_and_cost(): void
    {
        $simulated = $this->simulatedModel();
        $priced = $this->aiModel(['input' => '2', 'output' => '10'], ['is_default' => false]);
        LabAgent::fake(array_fill(0, 4, $this->fakeTextResponse('Respuesta de laboratorio', 100, 50)));

        $this->actingAs($this->userWithRole('admin'))->post(route('admin.ai.lab.store'), [
            'name' => 'Comparación',
            'prompts' => ['Hola', '¿Qué es Laravel?'],
            'model_ids' => [$simulated->id, $priced->id],
            'max_cost' => 1,
            'max_concurrency' => 2,
            'max_tokens' => 200,
        ])->assertRedirect();

        $run = LabRun::query()->sole();
        $this->assertSame(LabRun::COMPLETED, $run->status);
        $this->assertSame(4, $run->results()->where('status', LabResult::SUCCEEDED)->count());

        $result = $run->results()->where('model_id', $priced->id)->first();
        $this->assertSame('Respuesta de laboratorio', $result?->output);
        $this->assertNotNull($result->latency_ms);
        $this->assertNotNull($result->ttft_ms);
        $this->assertSame('0.0007000000', $result->cost);
        $this->assertSame('0.0014000000', (string) $run->spent);
        $this->assertSame(0.0, (float) $run->reserved);
        $this->assertSame(4, AiRequest::query()->where('feature', Feature::Lab->value)->count());

        $this->actingAs($this->userWithRole('admin'))->get(route('admin.ai.lab.show', $run))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('admin/ai/lab/Show')->has('run.results', 4)->has('run.models', 2));
    }

    public function test_tasks_that_exceed_the_lab_budget_are_skipped(): void
    {
        $simulated = $this->simulatedModel();
        $priced = $this->aiModel(['input' => '2', 'output' => '10'], ['is_default' => false]);
        LabAgent::fake(array_fill(0, 4, $this->fakeTextResponse('ok', 10, 10)));

        $this->actingAs($this->userWithRole('admin'))->post(route('admin.ai.lab.store'), [
            'name' => 'Sin presupuesto',
            'prompts' => ['Hola'],
            'model_ids' => [$simulated->id, $priced->id],
            'max_cost' => 0.000001,
            'max_concurrency' => 1,
        ]);

        $run = LabRun::query()->sole();
        $this->assertSame(LabResult::SUCCEEDED, $run->results()->where('model_id', $simulated->id)->value('status'));
        $this->assertSame(LabResult::SKIPPED, $run->results()->where('model_id', $priced->id)->value('status'));
        $this->assertSame(1, AiRequest::query()->count());
    }

    public function test_workers_respect_the_concurrency_and_runs_can_be_cancelled(): void
    {
        Queue::fake();
        $simulated = $this->simulatedModel();
        $admin = $this->userWithRole('admin');

        $this->actingAs($admin)->post(route('admin.ai.lab.store'), [
            'name' => 'En cola',
            'prompts' => ['uno', 'dos', 'tres', 'cuatro'],
            'model_ids' => [$simulated->id],
            'max_cost' => 1,
            'max_concurrency' => 2,
        ]);

        Queue::assertPushed(RunLabTasks::class, 2);

        $run = LabRun::query()->sole();
        $this->actingAs($admin)->post(route('admin.ai.lab.cancel', $run))->assertRedirect();

        $this->assertSame(LabRun::CANCELLED, $run->refresh()->status);
        $this->assertSame(4, $run->results()->where('status', LabResult::SKIPPED)->count());
    }

    public function test_the_api_exposes_recommendations_and_lab_runs(): void
    {
        $simulated = $this->simulatedModel();
        Sanctum::actingAs($this->userWithRole('admin'), ['read', 'write']);

        $this->postJson(route('api.v1.ai.recommendations.store'), ['use_case' => 'Chat interno'])
            ->assertOk()
            ->assertJsonPath('data.source', 'deterministic')
            ->assertJsonPath('data.recommendations.0.id', $simulated->id);

        $id = $this->postJson(route('api.v1.ai.lab-runs.store'), [
            'name' => 'API', 'prompts' => ['Hola'], 'model_ids' => [$simulated->id], 'max_cost' => 1, 'max_concurrency' => 1,
        ])->assertStatus(202)->json('data.id');

        $this->getJson(route('api.v1.ai.lab-runs.show', $id))
            ->assertOk()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.results.0.status', 'succeeded');

        Sanctum::actingAs($this->userWithRole('reader'), ['read', 'write']);
        $this->getJson(route('api.v1.ai.lab-runs.index'))->assertForbidden();
    }
}
