<?php

namespace Tests\Feature\Console;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Starter\Ai\Models\Agent;
use Starter\Ai\Models\Connection;
use Starter\Knowledge\Knowledge;
use Tests\Concerns\CreatesAiCatalog;
use Tests\TestCase;

/**
 * starter:config-apply: the non secret part of starter.config.json goes
 * to the database (settings, AI connections, agent, knowledge, owner).
 */
class ConfigApplyTest extends TestCase
{
    use CreatesAiCatalog, RefreshDatabase;

    /**
     * @param  array<string, mixed>  $overrides
     */
    protected function file(array $overrides = []): string
    {
        $config = array_replace_recursive((array) json_decode((string) file_get_contents(base_path('../../starter.config.example.json')), true), $overrides);
        $path = storage_path('framework/testing-apply-'.bin2hex(random_bytes(4)).'.json');
        file_put_contents($path, json_encode($config));
        $this->beforeApplicationDestroyed(fn () => @unlink($path));

        return $path;
    }

    /**
     * @return array<string, array{step: string, status: string, message: string}>
     */
    protected function apply(string $path, bool $dry = false): array
    {
        Artisan::call('starter:config-apply', ['path' => $path, '--json' => true, '--dry-run' => $dry]);

        return collect(json_decode(Artisan::output(), true)['steps'])->keyBy('step')->all();
    }

    public function test_it_applies_settings_connections_and_the_owner(): void
    {
        $this->seedPermissions();
        $path = $this->file([
            'project' => ['name' => 'Acme Projects', 'locale' => 'en-US', 'timezone' => 'Europe/Madrid'],
            'auth' => ['registration' => 'open'],
            'owner' => ['email' => 'Founder@Example.com', 'name' => 'Founder'],
            'ai' => ['connections' => [['ref' => 'primary-ai', 'driver' => 'openai', 'label' => 'OpenAI', 'credentialRef' => 'env:OPENAI_API_KEY']]],
        ]);

        // A dry run changes nothing...
        $steps = $this->apply($path, dry: true);
        $this->assertSame('ok', $steps['ai:primary-ai']['status']);
        $this->assertFalse(Connection::query()->where('name', 'primary-ai')->exists());
        $this->assertFalse(User::query()->where('email', 'founder@example.com')->exists());

        $steps = $this->apply($path);

        $this->assertSame('Acme Projects', settings('branding.app_name'));
        $this->assertSame('en', settings('general.locale'));
        $this->assertSame('Europe/Madrid', settings('general.timezone'));
        $this->assertSame('open', settings('auth.registration'));
        $this->assertSame('hola@example.com', settings('mail.from_address'));

        $connection = Connection::query()->where('name', 'primary-ai')->sole();
        $this->assertSame('env:OPENAI_API_KEY', $connection->secret_ref);
        $this->assertNull($connection->credentials);
        $this->assertTrue($connection->is_default);

        $this->assertTrue(User::query()->where('email', 'founder@example.com')->sole()->isOwner());
        $this->assertSame('ok', $steps['owner']['status']);
        $this->assertSame('skipped', $steps['knowledge']['status']);

        // Idempotent: applying again keeps one connection and one owner...
        $this->apply($path);
        $this->assertSame(1, Connection::query()->where('name', 'primary-ai')->count());
        $this->assertSame(1, User::query()->where('email', 'founder@example.com')->count());
    }

    public function test_keys_from_files_are_stored_encrypted_and_unknown_env_refs_are_refused(): void
    {
        $key = storage_path('framework/testing-key-'.bin2hex(random_bytes(4)));
        file_put_contents($key, "file-provider-secret\n");
        $this->beforeApplicationDestroyed(fn () => @unlink($key));

        $steps = $this->apply($this->file(['ai' => ['connectionRef' => null, 'connections' => [
            ['ref' => 'from-file', 'driver' => 'anthropic', 'credentialRef' => "file:{$key}"],
            ['ref' => 'other-env', 'driver' => 'openai', 'credentialRef' => 'env:APP_KEY'],
        ]]]));

        $connection = Connection::query()->where('name', 'from-file')->sole();
        $this->assertSame('file-provider-secret', $connection->credential('api_key'));
        $this->assertStringNotContainsString('file-provider-secret', (string) $connection->getRawOriginal('credentials'));
        $this->assertSame('warning', $steps['ai:other-env']['status']);
        $this->assertFalse(Connection::query()->where('name', 'other-env')->exists());
        $this->assertStringNotContainsString('file-provider-secret', json_encode($steps) ?: '');
    }

    public function test_the_agent_is_published_only_with_an_available_model(): void
    {
        $this->simulatedModel();
        $this->aiAgent(['instructions' => ['system_prompt' => 'Old prompt.', 'variables' => []]]);
        $agent = Agent::query()->where('is_default', true)->sole();
        $published = $agent->published_version_id;

        $steps = $this->apply($this->file(['ai' => ['connectionRef' => 'sim', 'connections' => [['ref' => 'sim', 'driver' => 'simulated']], 'agent' => ['systemPrompt' => 'New prompt for the team.', 'modelRef' => 'missing-model']]]));
        $this->assertSame('warning', $steps['agent']['status']);
        $this->assertSame($published, $agent->refresh()->published_version_id);
        $this->assertSame('New prompt for the team.', $agent->draft()->sole()->settings()->systemPrompt());

        $steps = $this->apply($this->file(['ai' => ['connectionRef' => 'sim', 'connections' => [['ref' => 'sim', 'driver' => 'simulated']], 'agent' => ['systemPrompt' => 'New prompt for the team.', 'modelRef' => 'simulated-chat', 'maxOutputTokens' => 900]]]));
        $this->assertSame('ok', $steps['agent']['status'], $steps['agent']['message']);
        $version = $agent->refresh()->publishedVersion()->sole();
        $this->assertNotSame($published, $version->id);
        $this->assertSame(900, $version->settings()->get('responses.max_tokens'));
    }

    public function test_internal_knowledge_is_activated_and_flags_are_compared(): void
    {
        config(['starter.features.rag' => true, 'starter.features.postgrest' => false]);
        $this->simulatedModel();

        $steps = $this->apply($this->file(['features' => ['rag' => true, 'postgrest' => true], 'rag' => ['mode' => 'internal', 'embeddingProfileRef' => 'default']]));

        $this->assertSame('ok', $steps['knowledge']['status'], $steps['knowledge']['message']);
        $this->assertTrue(app(Knowledge::class)->available());
        $this->assertSame('warning', $steps['environment']['status']);
        $this->assertStringContainsString('STARTER_FEATURE_POSTGREST=true', $steps['environment']['message']);
    }

    public function test_the_mail_provider_is_activated_only_with_a_usable_key(): void
    {
        config(['services.resend.key' => null]);
        $mail = ['mail' => ['transport' => 'resend', 'from' => 'hola@acme.test', 'credentialRef' => 'env:RESEND_API_KEY']];

        $steps = $this->apply($this->file($mail));

        $this->assertSame('warning', $steps['mail']['status']);
        $this->assertSame('env', settings('mail.mailer'));

        config(['services.resend.key' => 're_test1234_abcdefghijklmnopqrstuv']);

        $steps = $this->apply($this->file($mail));

        $this->assertSame('ok', $steps['mail']['status']);
        $this->assertSame('resend', settings('mail.mailer'));
        $this->assertSame('hola@acme.test', settings('mail.from_address'));
        // The key stays in the .env: nothing secret is stored...
        $this->assertNull(settings('mail.resend_key'));
    }

    public function test_smtp_is_read_from_a_url_reference(): void
    {
        putenv('STARTER_MAIL_SECRET_SMTP=smtps://mailer:p%40ss@smtp.acme.test:465');

        try {
            $steps = $this->apply($this->file(['mail' => ['transport' => 'smtp', 'from' => 'hola@acme.test', 'credentialRef' => 'env:STARTER_MAIL_SECRET_SMTP']]));
        } finally {
            putenv('STARTER_MAIL_SECRET_SMTP');
        }

        $this->assertSame('ok', $steps['mail']['status']);
        $this->assertSame('smtp', settings('mail.mailer'));
        $this->assertSame('smtp.acme.test', settings('mail.host'));
        $this->assertSame(465, settings('mail.port'));
        $this->assertSame('smtps', settings('mail.scheme'));
        $this->assertSame('mailer', settings('mail.username'));
        $this->assertSame('p@ss', settings('mail.password'));
    }

    public function test_an_invalid_file_changes_nothing(): void
    {
        $code = Artisan::call('starter:config-apply', ['path' => $this->file(['schemaVersion' => 5, 'project' => ['name' => 'Never applied']]), '--json' => true]);

        $this->assertSame(1, $code);
        $this->assertContains('/schemaVersion: must be 1', json_decode(Artisan::output(), true)['errors']);
        $this->assertNotSame('Never applied', settings('branding.app_name'));
    }
}
