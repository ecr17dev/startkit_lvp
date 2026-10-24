<?php

namespace Tests\Feature\Ai;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Sanctum\Sanctum;
use Starter\Ai\Agents\PingAgent;
use Starter\Ai\AiGateway;
use Starter\Ai\Enums\Feature;
use Starter\Ai\Models\Budget;
use Starter\Ai\Models\ExchangeRate;
use Tests\Concerns\CreatesAiCatalog;
use Tests\TestCase;

class UsageAndBudgetsTest extends TestCase
{
    use CreatesAiCatalog, RefreshDatabase;

    protected function recordCall(?User $user = null): void
    {
        PingAgent::fake([$this->fakeTextResponse('OK', 1000, 500)]);
        $model = $this->aiModel();

        app(AiGateway::class)->run($model, Feature::Chat, $user, fn (string $provider, string $name) => (new PingAgent)->prompt('hi', provider: $provider, model: $name));
    }

    public function test_the_dashboard_shows_costs_breakdowns_and_budgets(): void
    {
        $admin = $this->userWithRole('admin');
        $this->recordCall($admin);
        Budget::query()->create(['name' => 'Monthly', 'scope' => 'global', 'period' => 'monthly', 'limit_amount' => 10]);

        $this->actingAs($admin)
            ->get(route('admin.ai.usage'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/ai/Usage')
                ->where('totals.requests', 1)
                ->where('totals.cost', '0.0070000000')
                ->where('totals.unknown_cost_requests', 0)
                ->has('daily', 30)
                ->where('breakdowns.feature.0.key', 'chat')
                ->where('breakdowns.user.0.label', $admin->name)
                ->has('budgets', 1)
                ->has('requests.data', 1));
    }

    public function test_usage_can_be_exported_as_csv(): void
    {
        $admin = $this->userWithRole('admin');
        $this->recordCall($admin);

        $response = $this->actingAs($admin)->get(route('admin.ai.usage.export'));

        $response->assertOk();
        $this->assertStringContainsString('cost_status', $response->streamedContent());
        $this->assertStringContainsString('calculated', $response->streamedContent());
    }

    public function test_readers_cannot_see_the_dashboard(): void
    {
        $this->actingAs($this->userWithRole('reader'))->get(route('admin.ai.usage'))->assertForbidden();
    }

    public function test_budgets_are_managed_with_validation(): void
    {
        $admin = $this->userWithRole('admin');

        $this->actingAs($admin)->post(route('admin.ai.budgets.store'), [
            'name' => 'Chat', 'scope' => 'feature', 'scope_value' => 'teleport', 'period' => 'daily', 'limit_amount' => 5, 'hard_limit' => true, 'block_unknown_cost' => false, 'is_active' => true,
        ])->assertSessionHasErrors('scope_value');

        $this->actingAs($admin)->post(route('admin.ai.budgets.store'), [
            'name' => 'Chat', 'scope' => 'feature', 'scope_value' => 'chat', 'period' => 'daily', 'limit_amount' => 5, 'alert_thresholds' => [80, 100], 'hard_limit' => true, 'block_unknown_cost' => false, 'is_active' => true,
        ])->assertSessionHasNoErrors();

        $this->actingAs($admin)
            ->get(route('admin.ai.budgets.index'))
            ->assertInertia(fn (Assert $page) => $page->component('admin/ai/budgets/Index')->where('budgets.0.name', 'Chat')->where('budgets.0.status.spent', '0'));

        $this->assertDatabaseHas('audit_events', ['action' => 'ai.budgets.created']);
    }

    public function test_costs_can_be_displayed_in_clp_with_a_traceable_rate(): void
    {
        $admin = $this->userWithRole('admin');
        Http::fake(['mindicador.cl/*' => Http::response(['serie' => [['fecha' => '2026-10-23T03:00:00.000Z', 'valor' => 912.34]]])]);

        $this->actingAs($admin)->post(route('admin.ai.usage.exchange-rate'))->assertRedirect();
        $this->actingAs($admin)->put(route('admin.ai.usage.settings'), ['dashboard' => true, 'display_currency' => 'CLP'])->assertSessionHasNoErrors();

        $rate = ExchangeRate::latestFor('CLP');
        $this->assertNotNull($rate);
        $this->assertSame('mindicador.cl', $rate->source);

        $this->actingAs($admin)
            ->get(route('admin.ai.usage'))
            ->assertInertia(fn (Assert $page) => $page->where('exchangeRate.rate', '912.34000000')->where('settings.display_currency', 'CLP'));
    }

    public function test_the_api_exposes_usage_and_budgets(): void
    {
        $admin = $this->userWithRole('admin');
        $this->recordCall($admin);

        Sanctum::actingAs($admin, ['read', 'write']);

        $this->getJson(route('api.v1.ai.usage.summary'))->assertOk()->assertJsonPath('totals.requests', 1)->assertJsonPath('currency', 'USD');
        $this->getJson(route('api.v1.ai.usage.requests'))->assertOk()->assertJsonPath('data.0.cost_status', 'calculated');

        $this->postJson(route('api.v1.ai.budgets.store'), [
            'name' => 'API budget', 'scope' => 'global', 'period' => 'monthly', 'limit_amount' => 20, 'hard_limit' => false, 'block_unknown_cost' => false, 'is_active' => true,
        ])->assertCreated()->assertJsonPath('data.status.limit', '20.000000');

        $this->getJson(route('api.v1.ai.connections.index'))->assertOk()->assertJsonMissingPath('data.0.credentials');
    }
}
