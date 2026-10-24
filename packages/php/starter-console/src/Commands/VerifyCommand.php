<?php

namespace Starter\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Migrations\Migrator;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Starter\Console\Contracts\ContractChecks;
use Starter\Identity\Permissions;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * One command to decide whether an installation (or a change) is ready:
 * environment and services (doctor), starter.config.json, contracts in
 * sync with the code, no pending migrations, permission catalog applied,
 * an owner and no simulated AI in production. --with-tests also runs the
 * PHP test suite.
 */
class VerifyCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'starter:verify
        {--with-tests : Also run the PHP test suite (php artisan test)}
        {--skip-contracts : Skip the contract checks (OpenAPI, client, schemas)}
        {--json : Output the result as JSON}';

    /**
     * @var string
     */
    protected $description = 'Verify the installation: doctor, configuration, contracts, migrations, permissions, owner and AI mode';

    /**
     * @var list<array{step: string, status: string, message: string}>
     */
    protected array $steps = [];

    public function handle(ContractChecks $contracts, Migrator $migrator): int
    {
        $this->step('doctor', $this->callSilently('starter:doctor') === self::SUCCESS, 'Environment, extensions, database and required services.', 'Run `php artisan starter:doctor` for the details.');
        $this->step('config', $this->callSilently('starter:config-validate') === self::SUCCESS, 'starter.config.json is valid.', 'Run `php artisan starter:config-validate` for the errors.');

        if ($this->option('skip-contracts')) {
            $this->steps[] = ['step' => 'contracts', 'status' => 'skipped', 'message' => 'Skipped (--skip-contracts).'];
        } else {
            $checks = $contracts->run();
            $failed = array_values(array_filter($checks, fn (array $check) => $check['status'] === ContractChecks::FAILED));
            $this->step('contracts', $failed === [], 'OpenAPI, API reference, typed client, schemas and manifest match the code.', implode(' ', array_column($failed, 'message')));
        }

        $this->migrations($migrator);
        $this->permissions();
        $this->owner();
        $this->ai();

        if ($this->option('with-tests')) {
            $process = new Process([PHP_BINARY, 'artisan', 'test'], base_path(), null, null, 3600);
            $process->run(fn (string $type, string $output) => $this->option('json') ? null : $this->output->write($output));
            $this->step('tests', $process->isSuccessful(), 'The PHP test suite passes.', 'php artisan test failed.');
        }

        $passed = collect($this->steps)->every(fn (array $step) => $step['status'] !== 'failed');

        if ($this->option('json')) {
            $this->line((string) json_encode(['passed' => $passed, 'steps' => $this->steps], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return $passed ? self::SUCCESS : self::FAILURE;
        }

        foreach ($this->steps as $step) {
            $color = ['ok' => 'green', 'warning' => 'yellow', 'skipped' => 'gray'][$step['status']] ?? 'red';
            $this->components->twoColumnDetail($step['step'], "<fg={$color}>".strtoupper($step['status'])."</> {$step['message']}");
        }

        $passed
            ? $this->components->info('The starter is verified.')
            : $this->components->error('Verification failed.');

        return $passed ? self::SUCCESS : self::FAILURE;
    }

    protected function step(string $name, bool $ok, string $success, string $failure, string $failedStatus = 'failed'): void
    {
        $this->steps[] = ['step' => $name, 'status' => $ok ? 'ok' : $failedStatus, 'message' => $ok ? $success : $failure];
    }

    protected function migrations(Migrator $migrator): void
    {
        try {
            $files = $migrator->getMigrationFiles([database_path('migrations'), ...$migrator->paths()]);
            $pending = array_diff(array_keys($files), $migrator->getRepository()->getRan());
            $this->step('migrations', $pending === [], 'No pending migrations.', count($pending).' pending migrations. Run `php artisan migrate`.');
        } catch (Throwable $exception) {
            $this->step('migrations', false, '', 'Cannot read the migrations: '.$exception->getMessage());
        }
    }

    protected function permissions(): void
    {
        try {
            $catalog = Permissions::all();
            $missing = array_diff($catalog, Permission::query()->pluck('name')->all());
            $owner = Role::query()->where('name', Permissions::OWNER)->first();
            $ownerMissing = $owner === null ? $catalog : array_diff($catalog, $owner->permissions()->pluck('name')->all());

            $this->step(
                'permissions',
                $missing === [] && $ownerMissing === [],
                count($catalog).' catalog permissions applied; the owner role has all of them.',
                'The catalog is not applied ('.count($missing).' missing, owner lacks '.count($ownerMissing).'). Run `php artisan starter:permissions`.',
            );
        } catch (Throwable $exception) {
            $this->step('permissions', false, '', 'Cannot read the permissions: '.$exception->getMessage());
        }
    }

    protected function owner(): void
    {
        try {
            $owners = Role::query()->where('name', Permissions::OWNER)->first()?->users()->count() ?? 0;
            $this->step('owner', $owners > 0, "{$owners} owner(s).", 'No owner yet. Run `php artisan starter:owner`.', 'warning');
        } catch (Throwable $exception) {
            $this->step('owner', false, '', 'Cannot read the owners: '.$exception->getMessage());
        }
    }

    protected function ai(): void
    {
        $simulated = (bool) config('starter.ai.simulated');
        $production = app()->environment('production');

        if ($simulated && $production) {
            $this->step('ai', false, '', 'The simulated AI provider is enabled in production (STARTER_AI_SIMULATED).');
        } elseif ($simulated) {
            $this->steps[] = ['step' => 'ai', 'status' => 'warning', 'message' => 'Simulated AI provider enabled (local/testing): answers are not from a real model.'];
        } else {
            $this->step('ai', true, 'Real AI providers only.', '');
        }
    }
}
