<?php

namespace Tests\Feature\Ai;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Sanctum\Sanctum;
use Starter\Ai\Agents\AgentConfig;
use Starter\Ai\Agents\AgentManager;
use Starter\Ai\Enums\AgentVersionStatus;
use Starter\Ai\Enums\CapabilityStatus;
use Starter\Ai\Enums\Feature;
use Starter\Ai\Models\Agent;
use Starter\Ai\Models\AiRequest;
use Starter\Ai\Models\ModelCapability;
use Tests\Concerns\CreatesAiCatalog;
use Tests\TestCase;

class AgentsTest extends TestCase
{
    use CreatesAiCatalog, RefreshDatabase;

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function config(array $overrides = []): array
    {
        return array_replace_recursive(AgentConfig::defaults(), $overrides);
    }

    public function test_agents_go_through_draft_publication_and_rollback(): void
    {
        $this->simulatedModel();
        $admin = $this->userWithRole('admin');

        $this->actingAs($admin)->post(route('admin.ai.agents.store'), ['name' => 'Soporte', 'slug' => 'soporte'])
            ->assertRedirect();

        $agent = Agent::query()->where('slug', 'soporte')->sole();
        $this->assertSame(1, $agent->draft()->value('number'));

        $this->actingAs($admin)->put(route('admin.ai.agents.draft.update', $agent), [
            'config' => $this->config(['instructions' => ['system_prompt' => 'Primera versión', 'variables' => []]]),
            'notes' => 'v1',
        ])->assertSessionHasNoErrors();

        $this->actingAs($admin)->post(route('admin.ai.agents.publish', $agent))->assertSessionHasNoErrors();
        $this->assertSame(1, $agent->refresh()->publishedVersion?->number);

        $this->actingAs($admin)->put(route('admin.ai.agents.draft.update', $agent), [
            'config' => $this->config(['instructions' => ['system_prompt' => 'Segunda versión', 'variables' => []]]),
        ])->assertSessionHasNoErrors();

        // The draft does not change the published version until it is published...
        $this->assertSame('Primera versión', $agent->refresh()->publishedVersion?->settings()->systemPrompt());

        $this->actingAs($admin)->post(route('admin.ai.agents.publish', $agent), ['notes' => 'v2'])->assertSessionHasNoErrors();
        $this->assertSame('Segunda versión', $agent->refresh()->publishedVersion?->settings()->systemPrompt());

        $first = $agent->versions()->where('number', 1)->sole();
        $this->assertSame(AgentVersionStatus::Archived, $first->status);

        $this->actingAs($admin)->post(route('admin.ai.agents.versions.restore', ['agent' => $agent, 'version' => $first]))
            ->assertSessionHasNoErrors();

        $published = $agent->refresh()->publishedVersion;
        $this->assertSame(3, $published?->number);
        $this->assertSame('Primera versión', $published->settings()->systemPrompt());
        $this->assertSame(1, $agent->versions()->where('status', 'published')->count());

        foreach (['ai.agent.created', 'ai.agent.draft_saved', 'ai.agent.published', 'ai.agent.rolled_back'] as $action) {
            $this->assertDatabaseHas('audit_events', ['action' => $action]);
        }

        $this->actingAs($admin)->get(route('admin.ai.agents.edit', $agent))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/ai/agents/Edit')
                ->has('versions', 3)
                ->where('versions.0.number', 3)
                ->where('versions.0.changes', ['instructions']));
    }

    public function test_publication_is_blocked_by_catalog_errors(): void
    {
        $model = $this->simulatedModel();
        ModelCapability::query()->where('model_id', $model->id)->where('capability', 'vision')->update(['status' => CapabilityStatus::Unsupported]);
        $admin = $this->userWithRole('admin');
        $agent = $this->aiAgent();

        $this->actingAs($admin)->put(route('admin.ai.agents.draft.update', $agent), [
            'config' => $this->config([
                'execution' => ['model_id' => $model->id],
                'multimedia' => ['images' => true],
                'microphone' => ['enabled' => true],
            ]),
        ])->assertSessionHasNoErrors();

        $this->actingAs($admin)->post(route('admin.ai.agents.publish', $agent))->assertSessionHasErrors('draft');

        $this->assertSame(1, $agent->refresh()->publishedVersion?->number);
    }

    public function test_drafts_only_accept_registered_tools_and_known_variables(): void
    {
        $admin = $this->userWithRole('admin');
        $agent = $this->aiAgent();

        $this->actingAs($admin)->put(route('admin.ai.agents.draft.update', $agent), [
            'config' => $this->config([
                'tools' => ['allowed' => ['delete_everything']],
                'instructions' => ['system_prompt' => 'x', 'variables' => ['app_key']],
            ]),
        ])->assertSessionHasErrors(['config.tools.allowed.0', 'config.instructions.variables.0']);
    }

    public function test_the_system_prompt_only_renders_allowed_variables(): void
    {
        $config = AgentConfig::from($this->config([
            'identity' => ['language' => 'es'],
            'instructions' => ['system_prompt' => 'Hola {{user_name}}, clave {{user_email}}', 'variables' => ['user_name']],
        ]));

        $rendered = $config->render(['user_name' => 'Ana', 'user_email' => 'ana@example.com']);

        $this->assertStringStartsWith('Hola Ana, clave {{user_email}}', $rendered);
        $this->assertStringContainsString('Always answer in Spanish.', $rendered);
        $this->assertSame(['user_email'], $config->unknownVariables());
    }

    public function test_access_depends_on_roles_and_surfaces(): void
    {
        $this->simulatedModel();
        $this->aiAgent(['access' => ['roles' => ['admin'], 'surfaces' => ['page']]]);

        Sanctum::actingAs($this->userWithRole('admin'), ['ai']);
        $this->postJson(route('api.v1.ai.chat'), ['message' => 'hola'])->assertNotFound();

        Sanctum::actingAs($this->userWithRole('reader'), ['ai']);
        $this->getJson(route('api.v1.ai.chat.agents'))->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_api_chat_uses_the_published_version_and_records_it(): void
    {
        $this->simulatedModel();
        $agent = $this->aiAgent();
        app(AgentManager::class)->saveDraft($agent, $this->config(['instructions' => ['system_prompt' => 'borrador', 'variables' => []]]));
        // A reader cannot switch the agent to another (more expensive) model...
        $expensive = $this->aiModel(['input' => '15', 'output' => '60'], ['is_default' => false]);
        $reader = $this->userWithRole('reader');
        Sanctum::actingAs($reader, ['ai']);

        $response = $this->postJson(route('api.v1.ai.chat'), ['message' => 'Hola agente', 'model_id' => $expensive->id])
            ->assertOk()
            ->assertJsonPath('agent', 'assistant')
            ->assertJsonPath('agent_version', 1)
            ->assertJsonPath('model', 'simulated-chat')
            ->assertJsonPath('cost_status', 'calculated');

        $this->assertStringContainsString('[Simulated answer]', (string) $response->json('text'));

        $request = AiRequest::query()->sole();
        $this->assertSame($agent->published_version_id, $request->agent_version_id);
        $this->assertSame(Feature::Chat, $request->feature);
        $this->assertSame($reader->id, $request->user_id);
    }

    public function test_requests_per_minute_are_limited_per_user(): void
    {
        $this->simulatedModel();
        $this->aiAgent(['limits' => ['requests_per_minute' => 1, 'concurrent_per_user' => null]]);
        Sanctum::actingAs($this->userWithRole('reader'), ['ai']);

        $this->postJson(route('api.v1.ai.chat'), ['message' => 'uno'])->assertOk();
        $this->postJson(route('api.v1.ai.chat'), ['message' => 'dos'])->assertStatus(429)->assertHeader('Retry-After');
    }

    public function test_agent_budgets_block_calls_of_that_agent(): void
    {
        $model = $this->aiModel(['input' => '2', 'output' => '10']);
        $this->aiAgent(['execution' => ['model_id' => $model->id]]);
        $admin = $this->userWithRole('admin');

        $this->actingAs($admin)->post(route('admin.ai.budgets.store'), [
            'name' => 'Asistente', 'scope' => 'agent', 'scope_value' => 'assistant', 'period' => 'daily',
            'limit_amount' => 0.000001, 'hard_limit' => true, 'block_unknown_cost' => false, 'is_active' => true,
        ])->assertSessionHasNoErrors();

        Sanctum::actingAs($this->userWithRole('reader'), ['ai']);

        $this->postJson(route('api.v1.ai.chat'), ['message' => 'hola'])->assertStatus(429);
        $this->assertSame('blocked', AiRequest::query()->sole()->status->value);
    }

    public function test_the_admin_test_runs_the_draft_as_a_test_call(): void
    {
        $this->simulatedModel();
        $admin = $this->userWithRole('admin');
        $agent = $this->aiAgent();
        app(AgentManager::class)->saveDraft($agent, $this->config());

        $this->actingAs($admin)
            ->post(route('admin.ai.agents.test', $agent), ['message' => 'probando'])
            ->assertSessionHasNoErrors()
            ->assertInertiaFlash('agentTest.ok', true)
            ->assertInertiaFlash('agentTest.version', 2);

        $request = AiRequest::query()->sole();
        $this->assertSame(Feature::Test, $request->feature);
        $this->assertSame($agent->draft()->value('id'), $request->agent_version_id);
        $this->assertDatabaseCount('agent_conversations', 0);
    }

    public function test_agents_are_managed_through_the_api(): void
    {
        $this->simulatedModel();
        Sanctum::actingAs($this->userWithRole('admin'), ['read', 'write']);

        $id = $this->postJson(route('api.v1.ai.agents.store'), ['name' => 'Ventas', 'slug' => 'ventas'])
            ->assertCreated()
            ->json('data.id');

        $this->putJson(route('api.v1.ai.agents.draft.update', $id), ['config' => $this->config()])
            ->assertOk()
            ->assertJsonPath('data.check.errors', []);

        $this->postJson(route('api.v1.ai.agents.publish', $id))->assertOk()->assertJsonPath('data.status', 'published');

        $this->getJson(route('api.v1.ai.agents.show', $id))
            ->assertOk()
            ->assertJsonPath('data.published.number', 1)
            ->assertJsonPath('data.draft', null);

        Sanctum::actingAs($this->userWithRole('reader'), ['read', 'write']);
        $this->getJson(route('api.v1.ai.agents.index'))->assertForbidden();
    }
}
