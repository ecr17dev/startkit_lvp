<?php

namespace Tests\Feature\Ai;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Starter\Ai\Agents\ToolRegistry;
use Starter\Ai\Enums\ProposalStatus;
use Starter\Ai\Models\ActionProposal;
use Starter\Ai\Models\Agent;
use Tests\Concerns\CreatesAiCatalog;
use Tests\TestCase;

class OperatorToolsTest extends TestCase
{
    use CreatesAiCatalog, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->simulatedModel();
    }

    /**
     * @param  array<string, mixed>  $tools
     */
    protected function operatorAgent(array $tools = []): void
    {
        $this->aiAgent(['tools' => ['allowed' => app(ToolRegistry::class)->names(), 'max_steps' => 5, 'confirm_writes' => false, ...$tools]]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function events(TestResponse $response): array
    {
        $events = [];

        foreach (explode("\n\n", $response->streamedContent()) as $chunk) {
            $data = trim((string) preg_replace('/^data: /', '', trim($chunk)));

            if ($data !== '' && $data !== '[DONE]') {
                $events[] = json_decode($data, true);
            }
        }

        return $events;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<array<string, mixed>>
     */
    protected function chat(User $user, array $payload): array
    {
        return $this->events($this->actingAs($user)->post(route('ai.chat.stream'), $payload)->assertOk());
    }

    /**
     * @param  list<array<string, mixed>>  $events
     * @return list<array<string, mixed>>
     */
    protected function ofType(array $events, string $type): array
    {
        return array_values(array_filter($events, fn (array $event) => ($event['type'] ?? null) === $type));
    }

    /**
     * @param  list<array<string, mixed>>  $events
     */
    protected function conversation(array $events): string
    {
        return (string) $this->ofType($events, 'summary')[0]['conversation_id'];
    }

    public function test_reads_run_directly_and_return_real_data(): void
    {
        $this->operatorAgent();
        $admin = $this->userWithRole('admin');
        User::factory()->create(['name' => 'Ana Pérez', 'email' => 'ana@example.com']);

        $events = $this->chat($admin, ['message' => '/tool users_list {"search": "ana"}']);

        $result = $this->ofType($events, 'tool_result')[0]['result'] ?? null;
        $this->assertIsString($result);
        $this->assertStringContainsString('ana@example.com', $result);
        $this->assertSame(0, ActionProposal::query()->count());
        $this->assertDatabaseMissing('audit_events', ['action' => 'ai.tool.executed']);
    }

    public function test_sensitive_changes_wait_for_a_confirmed_proposal(): void
    {
        $this->operatorAgent();
        $admin = $this->userWithRole('admin');
        $target = User::factory()->create(['name' => 'Bruno']);

        $events = $this->chat($admin, ['message' => '/tool users_delete {"user_id": '.$target->id.'}']);

        $this->assertCount(1, $this->ofType($events, 'tool_approval_request'));
        $card = $this->ofType($events, 'proposal')[0]['proposal'];
        $this->assertSame('pending', $card['status']);
        $this->assertSame('Delete Bruno', $card['preview']['title']);
        $this->assertNotSoftDeleted($target);

        $proposal = ActionProposal::query()->sole();
        $this->assertSame($this->conversation($events), $proposal->conversation_id);
        $this->assertNotNull($proposal->ai_request_id);

        $events = $this->chat($admin, ['proposal_id' => $proposal->id, 'decision' => 'approve']);

        $this->assertSoftDeleted($target);
        $this->assertSame(ProposalStatus::Executed, $proposal->refresh()->status);
        $this->assertSame('executed', collect($this->ofType($events, 'proposal'))->last()['proposal']['status']);
        $this->assertDatabaseHas('audit_events', ['action' => 'ai.proposal.approved']);
        $this->assertDatabaseHas('audit_events', ['action' => 'ai.tool.executed']);
        $this->assertDatabaseHas('audit_events', ['action' => 'users.deleted']);

        // A proposal is used only once...
        $events = $this->chat($admin, ['proposal_id' => $proposal->id, 'decision' => 'approve']);
        $this->assertSame('error', $events[0]['type']);
    }

    public function test_rejected_expired_or_superseded_proposals_change_nothing(): void
    {
        $this->operatorAgent();
        $admin = $this->userWithRole('admin');
        $target = User::factory()->create();

        $this->chat($admin, ['message' => '/tool users_disable {"user_id": '.$target->id.'}']);
        $proposal = ActionProposal::query()->sole();

        $this->chat($admin, ['proposal_id' => $proposal->id, 'decision' => 'reject']);
        $this->assertSame(ProposalStatus::Rejected, $proposal->refresh()->status);
        $this->assertTrue($target->refresh()->is_active);
        $this->assertDatabaseHas('audit_events', ['action' => 'ai.proposal.rejected']);

        $events = $this->chat($admin, ['message' => '/tool users_disable {"user_id": '.$target->id.'}']);
        $second = ActionProposal::query()->where('status', 'pending')->sole();

        $this->travel(11)->minutes();
        $this->assertSame('error', $this->chat($admin, ['proposal_id' => $second->id, 'decision' => 'approve'])[0]['type']);
        $this->assertTrue($target->refresh()->is_active);

        $this->travelBack();
        $this->chat($admin, ['message' => '/tool users_disable {"user_id": '.$target->id.'}', 'conversation_id' => $this->conversation($events)]);
        $third = ActionProposal::query()->where('status', 'pending')->latest()->first();
        $this->chat($admin, ['message' => 'mejor no', 'conversation_id' => $this->conversation($events)]);
        $this->assertSame(ProposalStatus::Superseded, $third?->refresh()->status);
        $this->assertTrue($target->refresh()->is_active);
    }

    public function test_a_proposal_fails_when_the_resource_changed_before_confirmation(): void
    {
        $this->operatorAgent();
        $admin = $this->userWithRole('admin');
        $target = User::factory()->create();
        $target->assignRole('reader');

        $this->chat($admin, ['message' => '/tool users_assign_role {"user_id": '.$target->id.', "role": "editor"}']);
        $proposal = ActionProposal::query()->sole();

        // Someone changes the user in the meantime...
        $target->syncRoles(['admin']);
        $target->touch();

        $this->chat($admin, ['proposal_id' => $proposal->id, 'decision' => 'approve']);

        $this->assertSame(ProposalStatus::Failed, $proposal->refresh()->status);
        $this->assertSame(['admin'], $target->refresh()->getRoleNames()->all());
    }

    public function test_proposals_belong_to_their_actor(): void
    {
        $this->operatorAgent();
        $target = User::factory()->create();
        $this->chat($this->userWithRole('admin'), ['message' => '/tool users_delete {"user_id": '.$target->id.'}']);
        $proposal = ActionProposal::query()->sole();

        $this->actingAs($this->userWithRole('admin'))
            ->post(route('ai.chat.stream'), ['proposal_id' => $proposal->id, 'decision' => 'approve'])
            ->assertNotFound();

        $this->assertNotSoftDeleted($target);
        $this->assertSame(ProposalStatus::Pending, $proposal->refresh()->status);
    }

    public function test_tools_are_only_offered_with_their_permission_and_services_authorize_again(): void
    {
        $this->operatorAgent();
        $reader = $this->userWithRole('reader');
        $target = User::factory()->create();

        $events = $this->chat($reader, ['message' => '/tool users_delete {"user_id": '.$target->id.'}']);

        $this->assertCount(0, $this->ofType($events, 'tool_approval_request'));
        $this->assertSame(0, ActionProposal::query()->count());
        $this->assertNotSoftDeleted($target);

        // An administrator cannot delete their own account through the agent either...
        $admin = $this->userWithRole('admin');
        $events = $this->chat($admin, ['message' => '/tool users_delete {"user_id": '.$admin->id.'}']);

        $this->assertCount(0, $this->ofType($events, 'tool_approval_request'));
        $this->assertStringContainsString('not confirmed', (string) ($this->ofType($events, 'tool_result')[0]['result'] ?? ''));
        $this->assertNotSoftDeleted($admin);
    }

    public function test_ordinary_writes_run_directly_unless_the_agent_confirms_every_change(): void
    {
        $this->operatorAgent();
        $admin = $this->userWithRole('admin');

        $this->chat($admin, ['message' => '/tool users_create {"name": "Carla", "email": "carla@example.com", "roles": ["reader"]}']);

        $this->assertDatabaseHas('users', ['email' => 'carla@example.com']);
        $this->assertDatabaseHas('audit_events', ['action' => 'ai.tool.executed']);

        Agent::query()->delete();
        $this->operatorAgent(['confirm_writes' => true]);

        $events = $this->chat($admin, ['message' => '/tool users_create {"name": "Diego", "email": "diego@example.com"}']);

        $this->assertCount(1, $this->ofType($events, 'proposal'));
        $this->assertDatabaseMissing('users', ['email' => 'diego@example.com']);
    }

    public function test_delegation_prevents_granting_permissions_the_actor_lacks(): void
    {
        $this->operatorAgent();
        $admin = $this->userWithRole('admin');
        $target = User::factory()->create();

        // Administrators do not hold every permission of the owner role...
        $events = $this->chat($admin, ['message' => '/tool users_assign_role {"user_id": '.$target->id.', "role": "owner"}']);

        $this->assertCount(0, $this->ofType($events, 'proposal'));
        $this->assertFalse($target->refresh()->hasRole('owner'));
    }

    public function test_proposals_are_confirmed_through_the_api(): void
    {
        $this->operatorAgent();
        $admin = $this->userWithRole('admin');
        $target = User::factory()->create(['name' => 'Elena']);
        Sanctum::actingAs($admin, ['ai']);

        $response = $this->postJson(route('api.v1.ai.chat'), ['message' => '/tool users_disable {"user_id": '.$target->id.'}'])
            ->assertOk()
            ->assertJsonCount(1, 'proposals')
            ->assertJsonPath('proposals.0.preview.title', 'Disable Elena');

        $id = $response->json('proposals.0.id');

        $this->getJson(route('api.v1.admin.action-proposals.index', ['status' => 'pending']))->assertOk()->assertJsonCount(1, 'data');

        $this->postJson(route('api.v1.admin.action-proposals.confirm', $id))
            ->assertOk()
            ->assertJsonPath('data.status', 'executed')
            ->assertJsonPath('conversation_id', $response->json('conversation_id'));

        $this->assertFalse($target->refresh()->is_active);

        $this->postJson(route('api.v1.admin.action-proposals.confirm', $id))->assertStatus(409);

        Sanctum::actingAs($this->userWithRole('admin'), ['ai']);
        $this->getJson(route('api.v1.admin.action-proposals.show', $id))->assertNotFound();
    }
}
