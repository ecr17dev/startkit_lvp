<?php

namespace Tests\Feature\Ai;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Starter\Ai\Enums\ModelStatus;
use Starter\Ai\Models\AiModel;
use Starter\Ai\Models\Connection;
use Tests\Concerns\CreatesAiCatalog;
use Tests\TestCase;

class ConnectionsTest extends TestCase
{
    use CreatesAiCatalog, RefreshDatabase;

    public function test_connections_require_the_providers_permission(): void
    {
        $editor = $this->userWithRole('editor');

        $this->actingAs($editor)->get(route('admin.ai.connections.index'))->assertForbidden();
    }

    public function test_keys_are_stored_encrypted_and_never_shown(): void
    {
        $admin = $this->userWithRole('admin');

        $this->actingAs($admin)->post(route('admin.ai.connections.store'), [
            'name' => 'openai-main',
            'label' => 'OpenAI',
            'driver' => 'openai',
            'credential_mode' => 'stored',
            'api_key' => 'sk-live-super-secret-9876',
        ])->assertSessionHasNoErrors();

        $raw = (string) DB::table('ai_provider_connections')->value('credentials');
        $this->assertStringNotContainsString('super-secret', $raw);
        $this->assertSame('sk-live-super-secret-9876', Connection::query()->sole()->credential('api_key'));

        $this->actingAs($admin)
            ->get(route('admin.ai.connections.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('connections.0.masked_credentials.api_key', '••••••••9876')
                ->missing('connections.0.credentials'));

        $this->assertDatabaseHas('audit_events', ['action' => 'ai.connections.created']);
        $this->assertStringNotContainsString('super-secret', json_encode(DB::table('audit_events')->get()) ?: '');
    }

    public function test_blank_keys_keep_the_stored_one_and_new_keys_rotate(): void
    {
        $admin = $this->userWithRole('admin');
        $connection = $this->aiConnection();

        $this->actingAs($admin)->put(route('admin.ai.connections.update', $connection), ['label' => 'Renamed', 'api_key' => ''])->assertSessionHasNoErrors();
        $this->assertSame('sk-test-secret-1234', $connection->refresh()->credential('api_key'));
        $this->assertNull($connection->credentials_rotated_at);

        $this->actingAs($admin)->put(route('admin.ai.connections.update', $connection), ['api_key' => 'sk-new-key-0000'])->assertSessionHasNoErrors();
        $this->assertSame('sk-new-key-0000', $connection->refresh()->credential('api_key'));
        $this->assertNotNull($connection->credentials_rotated_at);
    }

    public function test_only_ai_keys_can_be_referenced_from_the_environment(): void
    {
        $admin = $this->userWithRole('admin');

        $this->actingAs($admin)->post(route('admin.ai.connections.store'), [
            'name' => 'leak', 'label' => 'Leak', 'driver' => 'openai', 'credential_mode' => 'env', 'secret_ref' => 'env:APP_KEY',
        ])->assertSessionHasErrors('secret_ref');

        $this->actingAs($admin)->post(route('admin.ai.connections.store'), [
            'name' => 'from-env', 'label' => 'From env', 'driver' => 'openai', 'credential_mode' => 'env', 'secret_ref' => 'env:OPENAI_API_KEY',
        ])->assertSessionHasNoErrors();

        $connection = Connection::query()->where('name', 'from-env')->sole();
        $this->assertNull($connection->credentials);
        $this->assertSame('env:OPENAI_API_KEY', $connection->maskedCredential('api_key'));
    }

    public function test_endpoints_are_validated_before_the_server_calls_them(): void
    {
        $admin = $this->userWithRole('admin');

        $this->actingAs($admin)->post(route('admin.ai.connections.store'), [
            'name' => 'meta', 'label' => 'Meta', 'driver' => 'openai-compatible', 'base_url' => 'http://169.254.169.254/latest', 'credential_mode' => 'stored',
        ])->assertSessionHasErrors('base_url');

        $this->actingAs($admin)->post(route('admin.ai.connections.store'), [
            'name' => 'plain', 'label' => 'Plain', 'driver' => 'openai', 'base_url' => 'http://127.0.0.1:8080/v1', 'api_key' => 'sk-x',
        ])->assertSessionHasErrors('base_url');

        $this->actingAs($admin)->post(route('admin.ai.connections.store'), [
            'name' => 'local-llm', 'label' => 'Local', 'driver' => 'ollama', 'base_url' => 'http://127.0.0.1:11434',
        ])->assertSessionHasNoErrors();
    }

    public function test_testing_a_connection_distinguishes_failures(): void
    {
        $admin = $this->userWithRole('admin');
        $connection = $this->aiConnection();

        Http::fake(['api.openai.com/*' => Http::sequence()
            ->push(['error' => ['message' => 'Incorrect API key']], 401)
            ->push(['error' => ['message' => 'You exceeded your current quota']], 429)
            ->push(['data' => [['id' => 'gpt-a'], ['id' => 'gpt-b']]])]);

        $this->actingAs($admin)->post(route('admin.ai.connections.test', $connection))->assertRedirect();
        $this->assertSame('authentication', $connection->refresh()->last_test_status);

        $this->actingAs($admin)->post(route('admin.ai.connections.test', $connection));
        $this->assertSame('quota', $connection->refresh()->last_test_status);

        $this->actingAs($admin)->post(route('admin.ai.connections.test', $connection));
        $this->assertSame('ok', $connection->refresh()->last_test_status);
    }

    public function test_discovered_models_start_disabled_and_unverified(): void
    {
        $admin = $this->userWithRole('admin');
        $connection = $this->aiConnection();

        Http::fake(['api.openai.com/v1/models' => Http::response(['data' => [['id' => 'gpt-new', 'owned_by' => 'openai'], ['id' => 'text-embedding-x']]])]);

        $this->actingAs($admin)->post(route('admin.ai.connections.discover', $connection))->assertRedirect();

        $models = AiModel::query()->where('connection_id', $connection->id)->orderBy('model')->get();
        $this->assertSame(['gpt-new', 'text-embedding-x'], $models->pluck('model')->all());
        $this->assertTrue($models->every(fn (AiModel $model) => ! $model->is_enabled && $model->status === ModelStatus::Unknown));
        $this->assertSame(['embeddings'], $models->last()?->functions);

        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer sk-test-secret-1234'));
    }
}
