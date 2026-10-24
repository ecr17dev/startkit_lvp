<?php

namespace Tests\Feature\Console;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Starter\Installer\Config\ConfigValidator;
use Starter\Knowledge\Enums\JobType;
use Starter\Knowledge\Models\RagJob;
use Tests\Concerns\CreatesAiCatalog;
use Tests\Concerns\CreatesKnowledge;
use Tests\TestCase;

/**
 * Operational commands of the starter: configuration validation,
 * contracts, verification, models, knowledge doctor and reindexes.
 */
class StarterCommandsTest extends TestCase
{
    use CreatesAiCatalog, CreatesKnowledge, RefreshDatabase;

    /**
     * @return array<string, mixed>
     */
    protected function example(): array
    {
        return (array) json_decode((string) file_get_contents(base_path('../../starter.config.example.json')), true);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    protected function configFile(array $config): string
    {
        $path = storage_path('framework/testing-config-'.bin2hex(random_bytes(4)).'.json');
        file_put_contents($path, json_encode($config));
        $this->beforeApplicationDestroyed(fn () => @unlink($path));

        return $path;
    }

    /**
     * @return array<string, mixed>
     */
    protected function runJson(string $command, array $parameters = []): array
    {
        Artisan::call($command, [...$parameters, '--json' => true]);

        return (array) json_decode(Artisan::output(), true);
    }

    public function test_the_example_configuration_is_valid(): void
    {
        $result = (new ConfigValidator)->validateFile(base_path('../../starter.config.example.json'));

        $this->assertTrue($result['valid'], implode("\n", $result['errors']));
        $this->assertContains('/ai/agent/modelRef: pending selection; the agent is published once a model is chosen', $result['warnings']);
    }

    public function test_the_installer_ships_the_same_schema_as_the_contract(): void
    {
        $this->assertFileEquals(base_path('../../tooling/contracts/starter.config.schema.json'), ConfigValidator::schemaPath());
    }

    public function test_the_schema_rejects_unknown_fields_wrong_types_and_plain_secrets(): void
    {
        $config = $this->example();
        $config['project']['color'] = 'blue';
        $config['schemaVersion'] = 2;
        $config['owner']['email'] = 'not-an-email';
        $config['ai']['connections'][0]['credentialRef'] = 'sk-proj-abcdefghijklmnopqrstuvwxyz123456';
        $config['ai']['agent']['systemPrompt'] = 'Use the key AKIAABCDEFGHIJKLMNOP when needed.';

        $result = (new ConfigValidator)->validate($config);

        $this->assertFalse($result['valid']);
        $this->assertContains('/project/color: is not allowed', $result['errors']);
        $this->assertContains('/schemaVersion: must be 1', $result['errors']);
        $this->assertContains('/owner/email: must be an email address', $result['errors']);
        $this->assertContains('/ai/connections/0/credentialRef: does not match ^(env:[A-Z][A-Z0-9_]*|file:/.+)$', $result['errors']);
        $this->assertContains('/ai/connections/0/credentialRef: looks like a plain secret (API key); reference it with env:NAME or file:/path', $result['errors']);
        $this->assertContains('/ai/agent/systemPrompt: looks like a plain secret (AWS access key); reference it with env:NAME or file:/path', $result['errors']);
    }

    public function test_cross_rules_block_incoherent_configurations(): void
    {
        $config = $this->example();
        $config['rag'] = ['mode' => 'internal'];
        $config['ai']['connectionRef'] = 'missing';
        $config['ai']['connections'][] = ['ref' => 'primary-ai', 'driver' => 'openai-compatible'];
        $config['ai']['agent']['microphone'] = ['enabled' => true];
        $config['database'] = ['mode' => 'external'];

        $errors = (new ConfigValidator)->validate($config)['errors'];

        $this->assertContains('/rag/mode: "internal" requires /features/rag = true', $errors);
        $this->assertContains('/rag/embeddingProfileRef: an embedding profile is required for the internal mode', $errors);
        $this->assertContains('/ai/connections: the reference "primary-ai" is declared more than once', $errors);
        $this->assertContains('/ai/connectionRef: "missing" is not declared in /ai/connections', $errors);
        $this->assertContains('/ai/connections/1/baseUrl: required for the openai-compatible driver', $errors);
        $this->assertContains('/ai/agent/microphone/connectionRef: the microphone requires a transcription connection', $errors);
        $this->assertContains('/database/ref: an external database requires a connection reference (env:NAME or file:/path)', $errors);

        $config = $this->example();
        $config['features']['rag'] = true;
        $config['rag'] = ['mode' => 'external-api'];
        $this->assertSame(['/rag/connectionRef: required for the external-api mode'], (new ConfigValidator)->validate($config)['errors']);
    }

    public function test_unresolved_references_are_reported_without_values(): void
    {
        $validator = new ConfigValidator;
        $config = $this->example();
        $config['mail']['credentialRef'] = 'file:/does/not/exist';

        $missing = $validator->unresolved($config, fn (string $name) => $name === 'OPENAI_API_KEY' ? 'secret-value' : null);

        $this->assertSame([
            '/database/ref: the environment variable DB_URL is not defined',
            '/mail/credentialRef: the file /does/not/exist does not exist or is not readable',
        ], $missing);
        $this->assertStringNotContainsString('secret-value', implode(' ', $missing));
    }

    public function test_config_validate_command_reports_errors_and_strict_warnings(): void
    {
        $this->artisan('starter:config-validate', ['path' => base_path('../../starter.config.example.json')])->assertSuccessful();
        $this->artisan('starter:config-validate', ['path' => base_path('../../starter.config.example.json'), '--strict' => true])->assertFailed();

        $broken = $this->configFile([...$this->example(), 'schemaVersion' => 3]);
        $result = $this->runJson('starter:config-validate', ['path' => $broken]);
        $this->assertFalse($result['passed']);
        $this->assertSame(['/schemaVersion: must be 1'], $result['errors']);

        file_put_contents($broken, '{"schemaVersion": ');
        $this->artisan('starter:config-validate', ['path' => $broken])->assertFailed();
    }

    public function test_the_api_contract_and_reference_match_the_code(): void
    {
        $this->artisan('starter:api-docs', ['--check' => true])->assertSuccessful();
    }

    public function test_verify_reports_every_step(): void
    {
        $this->artisan('starter:permissions')->assertSuccessful();
        config(['starter.ai.simulated' => true]);

        $result = $this->runJson('starter:verify', ['--skip-contracts' => true]);
        $steps = collect($result['steps'])->keyBy('step');

        $this->assertSame(['doctor', 'config', 'contracts', 'migrations', 'permissions', 'owner', 'ai'], $steps->keys()->all());
        $this->assertSame('ok', $steps['migrations']['status']);
        $this->assertSame('ok', $steps['permissions']['status']);
        $this->assertSame('warning', $steps['owner']['status']);
        $this->assertSame('warning', $steps['ai']['status']);

        // The simulated provider is never accepted in production...
        $this->app['env'] = 'production';
        $steps = collect($this->runJson('starter:verify', ['--skip-contracts' => true])['steps'])->keyBy('step');
        $this->assertSame('failed', $steps['ai']['status']);
    }

    public function test_models_sync_lists_the_connection_models(): void
    {
        $this->simulatedModel();

        $result = $this->runJson('starter:ai-models-sync', ['--connection' => ['simulated']]);

        $this->assertSame('ok', $result['connections'][0]['status']);
        $this->assertGreaterThan(0, $result['connections'][0]['total']);
        $this->artisan('starter:ai-models-sync', ['--connection' => ['missing']])->assertFailed();
    }

    public function test_rag_doctor_diagnoses_the_active_connection(): void
    {
        $this->knowledgeCollection();

        $result = $this->runJson('starter:rag-doctor');

        $this->assertTrue($result['passed']);
        $this->assertSame('internal', $result['summary']['mode']);
        $this->assertSame(['active' => 1], $result['summary']['collections']);
        $this->assertSame('internal', $result['connections'][0]['kind']);
        $this->artisan('starter:rag-diagnose', ['--json' => true])->assertSuccessful();
    }

    public function test_rag_reindex_runs_with_the_permissions_of_the_acting_user(): void
    {
        Queue::fake();
        $collection = $this->knowledgeCollection();
        $reader = $this->userWithRole('reader');
        $owner = $this->userWithRole('owner');

        $this->artisan('starter:rag-reindex', ['collections' => ['handbook']])->assertFailed();
        $this->artisan('starter:rag-reindex', ['collections' => ['handbook'], '--as' => $reader->email])->assertFailed();
        $this->assertSame(0, RagJob::query()->count());

        $this->artisan('starter:rag-reindex', ['--all' => true, '--dry-run' => true])->expectsOutputToContain('handbook')->assertSuccessful();
        $this->assertSame(0, RagJob::query()->count());

        $this->artisan('starter:rag-reindex', ['collections' => [(string) $collection->id], '--as' => $owner->email])->assertSuccessful();
        $this->assertSame(1, RagJob::query()->where('type', JobType::Reindex->value)->where('created_by', $owner->id)->count());
        $this->assertDatabaseHas('audit_events', ['action' => 'rag.collections.reindex', 'actor_id' => $owner->id]);

        // A second one is refused while the first is building...
        $this->artisan('starter:rag-reindex', ['collections' => ['handbook'], '--as' => $owner->email])->assertFailed();
    }
}
