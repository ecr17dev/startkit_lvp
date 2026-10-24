<?php

namespace Tests\Feature\Ai;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Ai\Exceptions\RateLimitedException;
use RuntimeException;
use Starter\Ai\Agents\PingAgent;
use Starter\Ai\Catalog\CatalogManager;
use Starter\Ai\Enums\Driver;
use Starter\Ai\Enums\ModelStatus;
use Starter\Ai\Models\AiModel;
use Starter\Ai\Models\AiRequest;
use Starter\Ai\Models\ModelPrice;
use Starter\Ai\Models\PriceSyncRun;
use Tests\Concerns\CreatesAiCatalog;
use Tests\TestCase;

class CatalogTest extends TestCase
{
    use CreatesAiCatalog, RefreshDatabase;

    protected function openRouterPayload(string $prompt = '0.000003'): array
    {
        return ['data' => [[
            'id' => 'anthropic/claude-test',
            'name' => 'Claude Test',
            'context_length' => 200000,
            'architecture' => ['input_modalities' => ['text', 'image'], 'output_modalities' => ['text']],
            'pricing' => ['prompt' => $prompt, 'completion' => '0.000015', 'input_cache_read' => '0.0000003'],
            'supported_parameters' => ['tools', 'temperature', 'response_format'],
            'top_provider' => ['max_completion_tokens' => 64000],
        ]]];
    }

    public function test_openrouter_catalog_brings_capabilities_and_gateway_prices(): void
    {
        $connection = $this->aiConnection(['name' => 'openrouter', 'driver' => Driver::OpenRouter]);
        Http::fake(['openrouter.ai/*' => Http::sequence()
            ->push($this->openRouterPayload())
            ->push($this->openRouterPayload())
            ->push($this->openRouterPayload('0.000004'))]);

        app(CatalogManager::class)->discover($connection);
        $model = AiModel::query()->where('model', 'anthropic/claude-test')->sole();

        $this->assertSame(200000, $model->context_window);
        $this->assertSame(64000, $model->max_output_tokens);
        $this->assertSame('supported', $model->capabilities()->where('capability', 'tools')->first()?->status->value);
        $this->assertSame('supported', $model->capabilities()->where('capability', 'vision')->first()?->status->value);
        $this->assertSame('unsupported', $model->capabilities()->where('capability', 'reasoning')->first()?->status->value);

        $run = app(CatalogManager::class)->syncOpenRouterPrices();
        $this->assertSame('succeeded', $run->status);

        $input = ModelPrice::query()->where('model_id', $model->id)->where('component', 'input')->whereNull('effective_to')->sole();
        $this->assertEquals(3, (float) $input->amount);
        $this->assertSame('per_million_tokens', $input->unit->value);
        $this->assertSame('gateway', $input->source->kind);

        // A new price closes the previous version...
        app(CatalogManager::class)->syncOpenRouterPrices();

        $this->assertSame(2, ModelPrice::query()->where('model_id', $model->id)->where('component', 'input')->count());
        $this->assertSame(2, PriceSyncRun::query()->count());
    }

    public function test_failed_syncs_are_recorded(): void
    {
        $this->aiConnection(['name' => 'openrouter', 'driver' => Driver::OpenRouter]);
        Http::fake(['openrouter.ai/*' => Http::response('down', 503)]);

        $run = app(CatalogManager::class)->syncOpenRouterPrices();

        $this->assertSame('failed', $run->status);
        $this->assertNotNull($run->error);
    }

    public function test_models_are_verified_with_a_recorded_minimal_call(): void
    {
        $admin = $this->userWithRole('admin');
        $model = $this->aiModel([], ['status' => ModelStatus::Unknown]);

        PingAgent::fake([$this->fakeTextResponse('OK', 12, 2)]);

        $this->actingAs($admin)->post(route('admin.ai.models.verify', $model))->assertRedirect();

        $this->assertSame(ModelStatus::Available, $model->refresh()->status);
        $this->assertNotNull($model->verified_at);
        $this->assertSame('test', AiRequest::query()->sole()->feature->value);
    }

    public function test_missing_models_are_marked_unavailable(): void
    {
        $admin = $this->userWithRole('admin');
        $model = $this->aiModel([], ['status' => ModelStatus::Unknown]);

        PingAgent::fake(fn () => throw new RuntimeException('The model `gpt-test` does not exist'));

        $this->actingAs($admin)->post(route('admin.ai.models.verify', $model));

        $this->assertSame(ModelStatus::Unavailable, $model->refresh()->status);
        $this->assertStringStartsWith('model_not_found', (string) $model->last_error);
    }

    public function test_rate_limits_do_not_mark_models_unavailable(): void
    {
        $admin = $this->userWithRole('admin');
        $model = $this->aiModel([], ['status' => ModelStatus::Unknown]);

        PingAgent::fake(fn () => throw RateLimitedException::forProvider('openai'));

        $this->actingAs($admin)->post(route('admin.ai.models.verify', $model));

        $this->assertSame(ModelStatus::Unknown, $model->refresh()->status);
    }

    public function test_the_catalog_can_be_searched_and_prices_edited(): void
    {
        $admin = $this->userWithRole('admin');
        $model = $this->aiModel(['input' => '1', 'output' => '2'], ['label' => 'Fast model']);

        $this->actingAs($admin)
            ->get(route('admin.ai.models.index', ['search' => 'fast']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('admin/ai/models/Index')->has('models.data', 1)->where('models.data.0.prices.input.amount', '1.0000000000'));

        $this->actingAs($admin)
            ->post(route('admin.ai.models.prices.store', $model), ['component' => 'output', 'amount' => '3.5', 'notes' => 'https://example.com/pricing'])
            ->assertSessionHasNoErrors();

        $this->assertSame(2, ModelPrice::query()->where('model_id', $model->id)->where('component', 'output')->count());
        $this->assertDatabaseHas('audit_events', ['action' => 'ai.prices.updated']);

        $this->actingAs($admin)
            ->get(route('admin.ai.models.show', $model))
            ->assertInertia(fn (Assert $page) => $page->component('admin/ai/models/Show')->has('priceHistory', 3));
    }

    public function test_models_can_be_imported_manually_with_capabilities(): void
    {
        $admin = $this->userWithRole('admin');
        $connection = $this->aiConnection(['driver' => Driver::Bedrock, 'name' => 'bedrock']);

        $this->actingAs($admin)->post(route('admin.ai.models.store'), [
            'connection_id' => $connection->id,
            'model' => 'global.anthropic.claude-test',
            'functions' => ['text'],
            'source_url' => 'https://docs.aws.amazon.com/bedrock/',
            'capabilities' => ['tools' => 'supported', 'vision' => 'unknown'],
        ])->assertSessionHasNoErrors();

        $model = AiModel::query()->where('model', 'global.anthropic.claude-test')->sole();
        $this->assertSame('manual', $model->source);
        $this->assertSame('supported', $model->capabilities()->where('capability', 'tools')->first()?->status->value);

        $this->actingAs($admin)->post(route('admin.ai.models.store'), [
            'connection_id' => $connection->id,
            'model' => 'x',
            'capabilities' => ['teleport' => 'supported'],
        ])->assertSessionHasErrors('capabilities');
    }
}
