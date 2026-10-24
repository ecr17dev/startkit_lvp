<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Starter\Ai\Agents\AgentManager;
use Starter\Ai\Enums\Driver;
use Starter\Ai\Models\Agent;
use Starter\Ai\Models\AiModel;
use Starter\Ai\Models\Connection;
use Starter\Ai\Simulated\SimulatedCatalog;
use Starter\Installer\Config\ConfigValidator;
use Starter\Knowledge\Enums\RagMode;
use Starter\Knowledge\Knowledge;
use Symfony\Component\Console\Output\BufferedOutput;
use Throwable;

/**
 * Apply the non secret part of starter.config.json to the database: name,
 * language, time zone, registration, sender, AI connections (keys stay as
 * env: references or encrypted from file: references), the default agent,
 * internal knowledge activation and the owner. Environment flags
 * (STARTER_FEATURE_*, STARTER_AUTH_METHOD, DB_*, MAIL_*) are written by the
 * installer; here they are only compared. Idempotent: run it again after
 * editing the file.
 */
class ConfigApplyCommand extends Command
{
    protected $signature = 'starter:config-apply
        {path? : starter.config.json (default: the project file)}
        {--dry-run : Show what would change without writing}
        {--json : Output the result as JSON}';

    protected $description = 'Apply starter.config.json to the application (settings, AI connections, agent, knowledge, owner)';

    /**
     * @var list<array{step: string, status: string, message: string}>
     */
    protected array $steps = [];

    /**
     * References of the file that point to the simulated provider.
     *
     * @var list<string>
     */
    protected array $simulated = [];

    public function handle(AgentManager $agents, Knowledge $knowledge): int
    {
        $path = $this->argument('path') ?: (is_file(base_path('../../starter.config.json')) ? base_path('../../starter.config.json') : base_path('starter.config.json'));
        $result = (new ConfigValidator)->validateFile($path);

        if (! $result['valid'] || $result['config'] === null) {
            return $this->finish(false, $result['errors']);
        }

        $config = $result['config'];
        $dry = (bool) $this->option('dry-run');

        $this->settings($config, $dry);
        $this->features($config);
        $this->connections((array) ($config['ai'] ?? []), $dry);
        $this->agent((array) ($config['ai'] ?? []), $agents, $dry);
        $this->knowledge($config, $knowledge, $dry);
        $this->owner((array) ($config['owner'] ?? []), $dry);

        return $this->finish(collect($this->steps)->every(fn (array $step) => $step['status'] !== 'failed'));
    }

    /**
     * @param  array<string, mixed>  $config
     */
    protected function settings(array $config, bool $dry): void
    {
        $locale = strtolower(substr((string) ($config['project']['locale'] ?? ''), 0, 2));
        $values = array_filter([
            'branding.app_name' => $config['project']['name'] ?? null,
            'general.locale' => array_key_exists($locale, (array) config('starter.locales')) ? $locale : null,
            'general.timezone' => in_array($config['project']['timezone'] ?? null, timezone_identifiers_list(), true) ? $config['project']['timezone'] : null,
            'auth.registration' => $config['auth']['registration'] ?? null,
            'mail.from_address' => $config['mail']['from'] ?? null,
            'mail.from_name' => isset($config['mail']['from']) ? ($config['project']['name'] ?? null) : null,
            'ai.dashboard' => $config['ai']['usage']['dashboard'] ?? null,
        ], fn ($value) => $value !== null);

        if (! $dry) {
            settings()->set($values);
        }

        $this->step('settings', 'ok', implode(', ', array_keys($values)));

        if (($config['ai']['usage']['record'] ?? true) === false) {
            $this->step('usage', 'warning', 'AI usage is always recorded in the ledger (ai.usage.record=false is ignored).');
        }
    }

    /**
     * @param  array<string, mixed>  $config
     */
    protected function features(array $config): void
    {
        $differences = [];

        foreach ((array) ($config['features'] ?? []) as $feature => $enabled) {
            if ((bool) config("starter.features.{$feature}") !== $enabled) {
                $differences[] = 'STARTER_FEATURE_'.strtoupper($feature).'='.($enabled ? 'true' : 'false');
            }
        }

        if (isset($config['auth']['method']) && $config['auth']['method'] !== config('starter.auth.method')) {
            $differences[] = 'STARTER_AUTH_METHOD='.$config['auth']['method'];
        }

        $differences === []
            ? $this->step('environment', 'ok', 'Feature flags and authentication method match the environment.')
            : $this->step('environment', 'warning', 'Set in apps/platform/.env and restart: '.implode(' ', $differences));
    }

    /**
     * @param  array<string, mixed>  $ai
     */
    protected function connections(array $ai, bool $dry): void
    {
        foreach ((array) ($ai['connections'] ?? []) as $item) {
            $ref = (string) $item['ref'];
            $driver = Driver::from((string) $item['driver']);
            $credential = $item['credentialRef'] ?? null;
            $exists = Connection::query()->where('name', $ref)->exists();

            if ($driver === Driver::Simulated) {
                $this->simulated[] = $ref;

                if (! $dry) {
                    app(SimulatedCatalog::class)->install();
                }

                $this->step("ai:{$ref}", 'ok', 'Simulated provider installed (clearly labelled, no network).');

                continue;
            }

            $attributes = ['label' => $item['label'] ?? $driver->label(), 'driver' => $driver, 'base_url' => $item['baseUrl'] ?? null, 'is_active' => true];
            $message = $exists ? 'updated' : 'created';

            if (is_string($credential) && str_starts_with($credential, 'env:')) {
                if (! Connection::isAllowedSecretRef($credential)) {
                    $this->step("ai:{$ref}", 'warning', "{$credential} is not an allowed AI key reference (use STARTER_AI_SECRET_* or a provider key variable); the connection was not changed.");

                    continue;
                }

                $attributes['secret_ref'] = $credential;
                $message .= ", key from {$credential}";
            } elseif (is_string($credential) && str_starts_with($credential, 'file:')) {
                $secret = is_readable(substr($credential, 5)) ? trim((string) file_get_contents(substr($credential, 5))) : '';

                if ($secret === '') {
                    $this->step("ai:{$ref}", 'failed', "The key file of {$ref} cannot be read.");

                    continue;
                }

                $attributes['secret_ref'] = null;
                $attributes['credentials'] = ['api_key' => $secret];
                $message .= ', key stored encrypted from the file';
            } else {
                $message .= ', key pending';
            }

            if (! $dry) {
                $connection = Connection::query()->firstOrNew(['name' => $ref]);
                $connection->forceFill($attributes);
                $connection->save();
                audit($exists ? 'ai.connection.updated' : 'ai.connection.created', $connection, ['source' => 'starter.config.json']);
            }

            $this->step("ai:{$ref}", 'ok', $message);
        }

        $default = $ai['connectionRef'] ?? null;

        if (is_string($default) && ! $dry && Connection::query()->where('name', $default)->exists()) {
            DB::transaction(function () use ($default) {
                Connection::query()->where('name', '!=', $default)->update(['is_default' => false]);
                Connection::query()->where('name', $default)->update(['is_default' => true]);
            });
        }
    }

    /**
     * @param  array<string, mixed>  $ai
     */
    protected function agent(array $ai, AgentManager $agents, bool $dry): void
    {
        $settings = (array) ($ai['agent'] ?? []);
        $agent = Agent::query()->where('is_default', true)->first();

        if ($settings === [] || $agent === null) {
            $this->step('agent', $settings === [] ? 'skipped' : 'warning', $settings === [] ? 'No agent settings.' : 'There is no default agent yet (run the seeders).');

            return;
        }

        $config = ($agent->draft()->first() ?? $agent->publishedVersion()->first())?->settings()->toArray() ?? [];
        $model = $this->model($ai['connectionRef'] ?? null, $settings['modelRef'] ?? null);
        $transcription = $this->model($settings['microphone']['connectionRef'] ?? null, $settings['microphone']['modelRef'] ?? null);

        $config = array_replace_recursive($config, array_filter([
            'instructions' => isset($settings['systemPrompt']) ? ['system_prompt' => $settings['systemPrompt']] : null,
            'responses' => isset($settings['maxOutputTokens']) ? ['max_tokens' => $settings['maxOutputTokens']] : null,
            'multimedia' => $settings['media'] ?? null,
            'execution' => $model !== null ? ['model_id' => $model->id] : null,
            'microphone' => isset($settings['microphone']) ? array_filter([
                'enabled' => ($settings['microphone']['enabled'] ?? false) && $transcription !== null,
                'transcription_model_id' => $transcription?->id,
                'max_seconds' => $settings['microphone']['maxSeconds'] ?? null,
                'auto_send' => $settings['microphone']['autoSend'] ?? null,
            ], fn ($value) => $value !== null) : null,
        ]));

        if ($dry) {
            $this->step('agent', 'ok', "Would update the draft of {$agent->slug}".($model ? " with {$model->model}" : ' (model pending)'));

            return;
        }

        if (isset($settings['name'])) {
            $agents->update($agent, ['name' => $settings['name']]);
        }

        $agents->saveDraft($agent, $config, 'starter.config.json');

        if ($model === null) {
            $this->step('agent', 'warning', "Draft of {$agent->slug} saved; choose a model (modelRef) and publish it from Admin → Agents.");

            return;
        }

        try {
            $agents->publish($agent, 'starter.config.json');
            $this->step('agent', 'ok', "{$agent->slug} published with {$model->model}.");
        } catch (ValidationException $exception) {
            $this->step('agent', 'warning', 'Draft saved but not published: '.collect($exception->errors())->flatten()->implode(' '));
        }
    }

    protected function model(mixed $connectionRef, mixed $modelRef): ?AiModel
    {
        if (! is_string($connectionRef) || ! is_string($modelRef)) {
            return null;
        }

        // A simulated connection of the file is the installed simulated catalog...
        $name = in_array($connectionRef, $this->simulated, true) ? 'simulated' : $connectionRef;

        return AiModel::query()
            ->whereHas('connection', fn ($query) => $query->where('name', $name))
            ->where('model', $modelRef)
            ->where('is_enabled', true)
            ->first();
    }

    /**
     * @param  array<string, mixed>  $config
     */
    protected function knowledge(array $config, Knowledge $knowledge, bool $dry): void
    {
        $mode = $config['rag']['mode'] ?? 'disabled';

        if ($mode === 'disabled' || ! ($config['features']['rag'] ?? false)) {
            $this->step('knowledge', 'skipped', 'Knowledge (RAG) is disabled in the configuration.');

            return;
        }

        if ($mode !== 'internal') {
            $this->step('knowledge', 'warning', "The {$mode} mode needs its connection: add it in Admin → Knowledge → Connections, diagnose and activate it.");

            return;
        }

        if (! $knowledge->featureEnabled()) {
            $this->step('knowledge', 'warning', 'Set STARTER_FEATURE_RAG=true to activate internal knowledge.');

            return;
        }

        if ($knowledge->available() || $dry) {
            $this->step('knowledge', 'ok', $knowledge->available() ? 'Internal knowledge is already active.' : 'Would activate internal knowledge after the diagnostics.');

            return;
        }

        // The configuration asks for internal knowledge: the extension is
        // created when the role may (otherwise the report gives the SQL)...
        $report = $knowledge->activate(RagMode::Internal, createExtension: true);
        $failures = collect($report->toArray()['checks'])->where('status', 'error')->map(fn (array $check) => "{$check['label']}: {$check['message']}".(isset($check['sql']) ? " ({$check['sql']})" : ''))->implode('; ');

        $this->step('knowledge', $report->passed() ? 'ok' : 'failed', $report->passed()
            ? 'Internal knowledge activated; create the embedding profile and collections in Admin → Knowledge.'
            : "Activation failed: {$failures}. Fix it and run php artisan starter:config-apply again.");
    }

    /**
     * @param  array<string, mixed>  $owner
     */
    protected function owner(array $owner, bool $dry): void
    {
        $email = $owner['email'] ?? null;

        if (! is_string($email)) {
            $this->step('owner', 'skipped', 'No owner in the configuration.');

            return;
        }

        $exists = User::query()->where('email', mb_strtolower($email))->exists();

        if (! $dry) {
            $buffer = new BufferedOutput;

            try {
                $code = $this->runCommand('starter:owner', array_filter(['email' => $email, '--name' => $owner['name'] ?? null]), $buffer);
            } catch (Throwable $exception) {
                $this->step('owner', 'failed', $exception->getMessage());

                return;
            }

            if ($code !== self::SUCCESS) {
                $this->step('owner', 'failed', trim($buffer->fetch()));

                return;
            }
        }

        $this->step('owner', 'ok', ($exists ? 'Owner role ensured for ' : 'Owner created: ').$email);
    }

    protected function step(string $name, string $status, string $message): void
    {
        $this->steps[] = ['step' => $name, 'status' => $status, 'message' => $message];
    }

    /**
     * @param  list<string>  $errors
     */
    protected function finish(bool $passed, array $errors = []): int
    {
        if ($this->option('json')) {
            $this->line((string) json_encode(['passed' => $passed, 'dry_run' => (bool) $this->option('dry-run'), 'errors' => $errors, 'steps' => $this->steps], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return $passed ? self::SUCCESS : self::FAILURE;
        }

        foreach ($errors as $error) {
            $this->components->error($error);
        }

        foreach ($this->steps as $step) {
            $color = ['ok' => 'green', 'warning' => 'yellow', 'skipped' => 'gray'][$step['status']] ?? 'red';
            $this->components->twoColumnDetail($step['step'], "<fg={$color}>".strtoupper($step['status'])."</> {$step['message']}");
        }

        return $passed ? self::SUCCESS : self::FAILURE;
    }
}
