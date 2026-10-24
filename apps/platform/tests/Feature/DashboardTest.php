<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Starter\Ai\Agents\PingAgent;
use Starter\Ai\AiGateway;
use Starter\Ai\Enums\Feature;
use Tests\Concerns\CreatesAiCatalog;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use CreatesAiCatalog, RefreshDatabase;

    public function test_guests_are_redirected_to_the_login_page()
    {
        $response = $this->get(route('dashboard'));
        $response->assertRedirect(route('login'));
    }

    public function test_authenticated_users_can_visit_the_dashboard()
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $response = $this->get(route('dashboard'));
        $response->assertOk();
    }

    public function test_the_month_spend_keeps_sub_micro_costs()
    {
        $admin = $this->userWithRole('admin');
        $model = $this->aiModel(['input' => '0.25', 'output' => '2', 'cache_read' => '0.025']);
        PingAgent::fake([$this->fakeTextResponse('OK', 1200, 400, cacheRead: 100)]);

        app(AiGateway::class)->run($model, Feature::Chat, $admin, fn (string $provider, string $name) => (new PingAgent)->prompt('hi', provider: $provider, model: $name));

        // 1100 * 0.25 + 100 * 0.025 + 400 * 2 per million = 0.0010775 USD...
        $this->actingAs($admin)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('stats.ai.month_cost', '0.0010775000'));
    }
}
