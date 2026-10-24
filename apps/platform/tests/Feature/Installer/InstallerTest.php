<?php

namespace Tests\Feature\Installer;

use Illuminate\Support\Facades\File;
use PharData;
use Starter\Installer\Console\Application;
use Starter\Installer\Console\NewCommand;
use Starter\Installer\Environment\EnvFile;
use Starter\Installer\Environment\SecretResolver;
use Starter\Installer\ExitCode;
use Starter\Installer\Process\ProcessRunner;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * The `laravel-starter` CLI (tooling/create-starter): configuration and
 * prerequisite checks, stable exit codes, verified templates and the
 * installation steps (external tools replaced by a recording runner).
 */
class InstallerTest extends TestCase
{
    protected string $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workspace = storage_path('framework/testing/installer-'.bin2hex(random_bytes(4)));
        File::ensureDirectoryExists($this->workspace);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->workspace);

        parent::tearDown();
    }

    /**
     * A runner that records the commands instead of running them.
     *
     * @param  array<string, bool>  $tools  binary => available
     */
    protected function runner(array $tools = [], ?string $fail = null): ProcessRunner
    {
        return new class($tools, $fail) extends ProcessRunner
        {
            /** @var list<string> */
            public array $commands = [];

            /** @param array<string, bool> $tools */
            public function __construct(protected array $tools, protected ?string $fail) {}

            public function run(array $command, string $cwd, array $env = [], ?callable $output = null, int $timeout = 1800): array
            {
                if ($command[0] === 'git') {
                    return parent::run($command, $cwd, $env, $output, $timeout);
                }

                $line = implode(' ', array_map(fn (string $part) => $part === PHP_BINARY ? 'php' : $part, $command));
                $this->commands[] = $line;

                return ['ok' => $this->fail === null || ! str_contains($line, $this->fail), 'output' => "ran {$line}"];
            }

            public function find(string $binary): ?string
            {
                return ($this->tools[$binary] ?? true) ? "/usr/bin/{$binary}" : null;
            }

            public function version(string $binary, string $flag = '--version'): ?string
            {
                return ($this->tools[$binary] ?? true) ? ($binary === 'node' ? 'v22.20.0' : "{$binary} 1.0") : null;
            }
        };
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  array<string, string>  $secrets
     * @return array{0: int, 1: array<string, mixed>}
     */
    protected function install(array $input, ?ProcessRunner $runner = null, array $secrets = []): array
    {
        $command = new NewCommand($runner ?? $this->runner(), new SecretResolver(fn (string $name) => $secrets[$name] ?? null));
        (new Application)->addCommand($command);
        $tester = new CommandTester($command);
        $code = $tester->execute([...$input, '--json' => true], ['interactive' => false]);

        return [$code, (array) json_decode($tester->getDisplay(), true)];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    protected function config(array $overrides = []): string
    {
        $config = array_replace_recursive((array) json_decode((string) file_get_contents(base_path('../../starter.config.example.json')), true), $overrides);
        $path = "{$this->workspace}/starter.config.json";
        file_put_contents($path, json_encode($config));

        return $path;
    }

    /**
     * @return array<string, string>
     */
    protected function steps(array $result): array
    {
        return array_column((array) $result['steps'], 'status', 'step');
    }

    public function test_the_validate_command_uses_the_contract(): void
    {
        $tester = new CommandTester((new Application)->find('validate'));

        $this->assertSame(ExitCode::OK, $tester->execute(['file' => base_path('../../starter.config.example.json')]));
        $this->assertSame(ExitCode::CONFIG_INVALID, $tester->execute(['file' => $this->config(['schemaVersion' => 9]), '--json' => true]));
        $this->assertSame(['/schemaVersion: must be 1'], json_decode($tester->getDisplay(), true)['errors']);
    }

    public function test_invalid_input_stops_with_stable_codes_before_touching_the_disk(): void
    {
        $target = "{$this->workspace}/demo";

        [$code, $result] = $this->install(['directory' => '../escape']);
        $this->assertSame(ExitCode::USAGE, $code);

        [$code, $result] = $this->install(['directory' => $target, '--config' => $this->config(['ai' => ['connections' => [['ref' => 'main', 'driver' => 'openai', 'credentialRef' => 'sk-proj-abcdefghijklmnopqrstuv0123']]]])]);
        $this->assertSame(ExitCode::CONFIG_INVALID, $code);
        $this->assertStringContainsString('looks like a plain secret', implode(' ', $result['errors']));

        [$code, $result] = $this->install(['directory' => $target, '--config' => $this->config(['database' => ['mode' => 'external', 'ref' => 'env:MISSING_DB_URL']])]);
        $this->assertSame(ExitCode::PREREQUISITES, $code);
        $this->assertSame('failed', $this->steps($result)['secrets']);

        [$code, $result] = $this->install(['directory' => $target, '--config' => $this->config()], $this->runner(['pnpm' => false]));
        $this->assertSame(ExitCode::PREREQUISITES, $code);
        $this->assertStringContainsString('pnpm: not found', collect($result['steps'])->firstWhere('step', 'prerequisites')['detail']);

        // Without installing, pnpm is optional...
        [$code] = $this->install(['directory' => $target, '--config' => $this->config(), '--skip-install' => true, '--dry-run' => true], $this->runner(['pnpm' => false]));
        $this->assertSame(ExitCode::OK, $code);

        File::ensureDirectoryExists($target);
        File::put("{$target}/keep.txt", 'existing');
        [$code] = $this->install(['directory' => $target, '--config' => $this->config()]);
        $this->assertSame(ExitCode::TARGET_EXISTS, $code);

        $this->assertSame(['keep.txt'], array_values(array_diff(scandir($target) ?: [], ['.', '..'])));
    }

    public function test_dry_run_shows_the_plan_and_creates_nothing(): void
    {
        $target = "{$this->workspace}/demo";

        [$code, $result] = $this->install(['directory' => $target, '--config' => $this->config(), '--template' => base_path('../..'), '--dry-run' => true]);

        $this->assertSame(ExitCode::OK, $code);
        $this->assertTrue($result['dry_run']);
        $this->assertSame('planned', $this->steps($result)['composer']);
        $this->assertSame('skipped', $this->steps($result)['services']);
        $this->assertSame('skipped', $this->steps($result)['migrate'], 'No database without --start-services');
        $this->assertDirectoryDoesNotExist($target);
    }

    public function test_it_creates_a_project_from_a_local_checkout_with_local_secrets(): void
    {
        $target = "{$this->workspace}/demo";
        $config = $this->config([
            'project' => ['name' => 'Mi "Demo"'],
            'features' => ['rag' => true, 'postgrest' => true],
            'rag' => ['mode' => 'internal', 'embeddingProfileRef' => 'default'],
            'database' => ['mode' => 'external', 'ref' => 'env:DEMO_DB_URL'],
            'mail' => ['transport' => 'resend', 'credentialRef' => 'env:DEMO_RESEND'],
        ]);
        $runner = $this->runner();

        [$code, $result] = $this->install(
            ['directory' => $target, '--config' => $config, '--template' => base_path('../..'), '--skip-install' => true],
            $runner,
            ['DEMO_DB_URL' => 'pgsql://demo:db-secret@db.internal:5432/demo', 'DEMO_RESEND' => 're_secret_value', 'OPENAI_API_KEY' => 'provider-secret'],
        );

        $this->assertSame(ExitCode::OK, $code, json_encode($result) ?: '');
        $this->assertSame([], $runner->commands, 'Nothing is installed with --skip-install');
        $this->assertFileExists("{$target}/apps/platform/artisan");
        $this->assertFileExists("{$target}/apps/platform/storage/framework/cache/.gitignore");
        $this->assertDirectoryDoesNotExist("{$target}/apps/platform/vendor");
        $this->assertDirectoryDoesNotExist("{$target}/node_modules");
        $this->assertFileDoesNotExist("{$target}/.git/HEAD");

        // The agent context comes along: CLAUDE.md and the skill links...
        $this->assertFileExists("{$target}/CLAUDE.md");
        $this->assertSame('../../skills/build-ui', readlink("{$target}/.claude/skills/build-ui"));
        $this->assertFileExists("{$target}/.claude/skills/build-ui/SKILL.md");

        $env = EnvFile::fromFile("{$target}/apps/platform/.env");
        $this->assertSame('Mi "Demo"', $env->get('APP_NAME'));
        $this->assertStringContainsString('APP_NAME="Mi \\"Demo\\""', $env->contents());
        $this->assertMatchesRegularExpression('/^base64:[A-Za-z0-9+\/=]{44}$/', (string) $env->get('APP_KEY'));
        $this->assertSame('pgsql://demo:db-secret@db.internal:5432/demo', $env->get('DB_URL'));
        $this->assertSame('resend', $env->get('MAIL_MAILER'));
        $this->assertSame('re_secret_value', $env->get('RESEND_API_KEY'));
        $this->assertSame('provider-secret', $env->get('OPENAI_API_KEY'));
        $this->assertSame('true', $env->get('STARTER_FEATURE_RAG'));
        $this->assertSame(64, strlen((string) $env->get('POSTGREST_JWT_SECRET')));
        $this->assertSame('0600', substr(sprintf('%o', fileperms("{$target}/apps/platform/.env")), -4));
        $this->assertSame((string) $env->get('POSTGREST_JWT_SECRET'), EnvFile::fromFile("{$target}/docker/.env")->get('POSTGREST_JWT_SECRET'));

        // The exported configuration keeps references, never values...
        $exported = (string) file_get_contents("{$target}/starter.config.json");
        $this->assertStringContainsString('env:DEMO_DB_URL', $exported);
        $this->assertStringNotContainsString('db-secret', $exported);
        $this->assertStringNotContainsString('provider-secret', $exported);
        $this->assertStringNotContainsString('db-secret', json_encode($result) ?: '');
    }

    public function test_the_installation_runs_the_steps_in_order_and_stops_at_the_first_failure(): void
    {
        $target = "{$this->workspace}/demo";
        $config = $this->config(['database' => ['mode' => 'external', 'ref' => 'env:DEMO_DB_URL']]);
        $secrets = ['DEMO_DB_URL' => 'pgsql://demo:x@127.0.0.1:5432/demo'];
        $runner = $this->runner();

        [$code, $result] = $this->install(['directory' => $target, '--config' => $config, '--template' => base_path('../..')], $runner, $secrets);

        $this->assertSame(ExitCode::OK, $code, json_encode($result) ?: '');
        $this->assertSame([
            'composer install --no-interaction --prefer-dist --no-progress',
            'pnpm install --frozen-lockfile',
            'pnpm build',
            'php artisan migrate --seed --force --no-interaction',
            'php artisan starter:config-apply ../../starter.config.json --no-interaction',
            'php artisan starter:doctor --no-interaction',
        ], $runner->commands);
        $this->assertSame('skipped', $this->steps($result)['services']);

        File::deleteDirectory($target);
        $failing = $this->runner(fail: 'migrate');
        [$code, $result] = $this->install(['directory' => $target, '--config' => $config, '--template' => base_path('../..')], $failing, $secrets);

        $this->assertSame(ExitCode::STEP_FAILED, $code);
        $this->assertSame('migrate', $result['failed_step']);
        $this->assertNotContains('php artisan starter:doctor --no-interaction', $failing->commands);

        // Docker is only used when allowed explicitly...
        File::deleteDirectory($target);
        $docker = $this->runner();
        [$code] = $this->install(['directory' => $target, '--config' => $this->config(), '--template' => base_path('../..'), '--start-services' => true], $docker);
        $this->assertSame(ExitCode::OK, $code);
        $this->assertStringStartsWith('docker compose -f docker/compose.yaml', $docker->commands[3]);
    }

    public function test_release_archives_are_verified_before_extraction(): void
    {
        $source = "{$this->workspace}/build/laravel-starter";
        File::ensureDirectoryExists("{$source}/apps/platform");
        File::put("{$source}/apps/platform/artisan", '<?php');
        File::put("{$source}/apps/platform/.env.example", "APP_NAME=Laravel\nAPP_KEY=\n");
        File::put("{$source}/README.md", 'template');
        File::ensureDirectoryExists("{$source}/skills/demo-skill");
        File::put("{$source}/skills/demo-skill/SKILL.md", "---\nname: demo-skill\n---\n");

        $archive = "{$this->workspace}/laravel-starter-template-0.1.0.tar";
        (new PharData($archive))->buildFromDirectory("{$this->workspace}/build");
        (new PharData($archive))->compress(\Phar::GZ);
        $archive .= '.gz';
        $checksum = (string) hash_file('sha256', $archive);

        $input = ['--config' => $this->config(), '--skip-install' => true];

        [$code, $result] = $this->install(['directory' => "{$this->workspace}/bad", '--archive' => $archive, '--sha256' => str_repeat('a', 64), ...$input]);
        $this->assertSame(ExitCode::TEMPLATE, $code);
        $this->assertStringContainsString('Checksum mismatch', collect($result['steps'])->firstWhere('step', 'template')['detail']);

        [$code] = $this->install(['directory' => "{$this->workspace}/none", '--archive' => $archive, ...$input]);
        $this->assertSame(ExitCode::TEMPLATE, $code, 'Without a checksum nothing is extracted');

        File::put("{$archive}.sha256", "{$checksum}  laravel-starter-template-0.1.0.tar.gz\n");
        [$code, $result] = $this->install(['directory' => "{$this->workspace}/good", '--archive' => $archive, ...$input]);
        $this->assertSame(ExitCode::OK, $code, json_encode($result) ?: '');
        $this->assertSame('template', (string) file_get_contents("{$this->workspace}/good/README.md"));
        $this->assertNotEmpty(EnvFile::fromFile("{$this->workspace}/good/apps/platform/.env")->get('APP_KEY'));
        $this->assertSame('../../skills/demo-skill', readlink("{$this->workspace}/good/.claude/skills/demo-skill"));

        // Downloads accept https:// or file:// only, and verify the published checksum...
        [$code] = $this->install(['directory' => "{$this->workspace}/http", '--release' => '0.1.0', ...$input]);
        $this->assertSame(ExitCode::TEMPLATE, $code);
        putenv('LARAVEL_STARTER_RELEASE_URL=file://'.$this->workspace.'/laravel-starter-template-{version}.tar.gz');

        try {
            [$code, $result] = $this->install(['directory' => "{$this->workspace}/download", '--release' => 'v0.1.0', ...$input]);
            $this->assertSame(ExitCode::OK, $code, json_encode($result) ?: '');
            $this->assertFileExists("{$this->workspace}/download/apps/platform/artisan");
        } finally {
            putenv('LARAVEL_STARTER_RELEASE_URL');
        }
    }

    public function test_the_executable_runs(): void
    {
        $process = new Process([PHP_BINARY, base_path('../../tooling/create-starter/bin/laravel-starter'), '--version']);
        $process->run();

        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
        $this->assertStringContainsString('Laravel Starter installer '.Application::VERSION, $process->getOutput());
    }
}
