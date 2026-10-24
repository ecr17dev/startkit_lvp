<?php

namespace Tests\Feature\Ai;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Ai\Models\Conversation;
use Starter\Ai\Enums\RequestStatus;
use Starter\Ai\Models\AiRequest;
use Tests\Concerns\CreatesAiCatalog;
use Tests\TestCase;

class ChatTest extends TestCase
{
    use CreatesAiCatalog, RefreshDatabase;

    /**
     * Decode the server-sent events of a streamed response.
     *
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

    public function test_the_chat_lists_the_agents_available_to_the_user(): void
    {
        $this->simulatedModel();
        $this->aiAgent(['identity' => ['avatar' => '✨', 'greeting' => 'Hola']]);
        $this->aiAgent(['access' => ['roles' => ['admin'], 'surfaces' => ['page']]], 'interno', false);

        $this->actingAs($this->userWithRole('reader'))
            ->get(route('ai.chat'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('ai/Chat')
                ->has('agents', 1)
                ->where('agents.0.avatar', '✨')
                ->where('agents.0.greeting', 'Hola'));
    }

    public function test_answers_stream_and_the_ledger_records_the_conversation(): void
    {
        $this->simulatedModel();
        $agent = $this->aiAgent();
        $user = $this->userWithRole('reader');

        $events = $this->events($this->actingAs($user)->post(route('ai.chat.stream'), ['message' => 'Hola desde la prueba'])->assertOk());

        $text = implode('', array_map(fn (array $event) => $event['delta'] ?? '', array_filter($events, fn (array $event) => ($event['type'] ?? null) === 'text_delta')));
        $this->assertStringContainsString('[Simulated answer]', $text);

        $summary = collect($events)->firstWhere('type', 'summary');
        $this->assertNotNull($summary);
        $this->assertSame('calculated', $summary['cost_status']);

        $request = AiRequest::query()->sole();
        $this->assertSame(RequestStatus::Succeeded, $request->status);
        $this->assertSame($agent->published_version_id, $request->agent_version_id);
        $this->assertSame($summary['conversation_id'], $request->conversation_id);
        $this->assertSame(1, Conversation::query()->where('participant_id', $user->id)->count());
    }

    public function test_the_stream_explains_when_no_agent_is_available(): void
    {
        $events = $this->events($this->actingAs($this->userWithRole('reader'))->post(route('ai.chat.stream'), ['message' => 'hola'])->assertOk());

        $this->assertSame('error', $events[0]['type']);
        $this->assertDatabaseCount('ai_requests', 0);
    }

    public function test_conversations_of_other_users_cannot_be_continued(): void
    {
        $this->simulatedModel();
        $this->aiAgent();
        $owner = $this->userWithRole('reader');

        $events = $this->events($this->actingAs($owner)->post(route('ai.chat.stream'), ['message' => 'privado']));
        $conversation = collect($events)->firstWhere('type', 'summary')['conversation_id'];

        $this->actingAs($this->userWithRole('reader'))
            ->post(route('ai.chat.stream'), ['message' => 'intruso', 'conversation_id' => $conversation])
            ->assertNotFound();
    }
}
