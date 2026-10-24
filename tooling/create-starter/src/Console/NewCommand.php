<?php

namespace Starter\Installer\Console;

use Starter\Installer\Config\ConfigValidator;
use Starter\Installer\Environment\EnvFile;
use Starter\Installer\Environment\Prerequisites;
use Starter\Installer\Environment\SecretResolver;
use Starter\Installer\ExitCode;
use Starter\Installer\Process\ProcessRunner;
use Starter\Installer\Template\TemplateException;
use Starter\Installer\Template\TemplateSource;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/**
 * laravel-starter new <directory>: validate the configuration, check the
 * prerequisites, create the project from a verified template, write its
 * .env with local secrets, install with the lock files, optionally start
 * the local services, migrate, apply the configuration, create the owner
 * and run the diagnostics. Credentials only come from env:/file:
 * references; nothing secret is printed or exported.
 */
class NewCommand extends Command
{
    /**
     * @var list<array{step: string, status: string, detail: string}>
     */
    protected array $steps = [];

    /**
     * @var list<string>
     */
    protected array $warnings = [];

    protected InputInterface $input;

    protected OutputInterface $output;

    protected SymfonyStyle $io;

    public function __construct(
        protected ProcessRunner $runner = new ProcessRunner,
        protected ?SecretResolver $secrets = null,
        protected string $version = '0.1.0',
    ) {
        parent::__construct('new');
        $this->secrets ??= new SecretResolver;
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Create a new project from the Laravel starter template')
            ->addArgument('directory', InputArgument::REQUIRED, 'Directory of the new project')
            ->addOption('config', 'c', InputOption::VALUE_REQUIRED, 'starter.config.json to apply (validated against the contract)')
            ->addOption('template', null, InputOption::VALUE_REQUIRED, 'Local starter checkout to copy instead of a release')
            ->addOption('archive', null, InputOption::VALUE_REQUIRED, 'Local release archive (.tar.gz), verified with --sha256 or <archive>.sha256')
            ->addOption('release', null, InputOption::VALUE_REQUIRED, 'Release version to download (default: the installer version)')
            ->addOption('sha256', null, InputOption::VALUE_REQUIRED, 'Expected SHA-256 of the archive')
            ->addOption('url', null, InputOption::VALUE_REQUIRED, 'APP_URL of the project', 'http://localhost:8000')
            ->addOption('skip-install', null, InputOption::VALUE_NONE, 'Do not install dependencies (also skips migrations and diagnostics)')
            ->addOption('skip-migrate', null, InputOption::VALUE_NONE, 'Do not migrate, apply the configuration nor diagnose')
            ->addOption('start-services', null, InputOption::VALUE_NONE, 'Start PostgreSQL and Mailpit with Docker Compose (never done by default)')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Allow a non empty target directory')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Validate and show the plan without creating anything')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output the result as JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->input = $input;
        $this->output = $output;
        $this->io = new SymfonyStyle($input, $output);
        $directory = (string) $input->getArgument('directory');
        $target = str_starts_with($directory, '/') ? $directory : getcwd().'/'.$directory;
        $dry = (bool) $input->getOption('dry-run');

        if (! preg_match('/^[A-Za-z0-9._\-\/]+$/', $directory) || str_contains($directory, '..')) {
            return $this->finish(ExitCode::USAGE, $target, ['error' => 'Invalid directory name (letters, digits, ., _, - and /).']);
        }

        // 1. Configuration...
        try {
            $config = $this->configuration(basename($target));
        } catch (Throwable $exception) {
            return $this->finish(ExitCode::USAGE, $target, ['error' => $exception->getMessage()]);
        }

        $validation = (new ConfigValidator)->validate($config);
        $this->warnings = $validation['warnings'];

        if (! $validation['valid']) {
            $this->step('config', 'failed', implode('; ', $validation['errors']));

            return $this->finish(ExitCode::CONFIG_INVALID, $target, ['errors' => $validation['errors']]);
        }

        $this->step('config', 'ok', 'starter.config.json is valid');

        // 2. Secrets that the installation needs...
        if (($config['database']['mode'] ?? 'docker') === 'external' && $this->secrets?->resolve($config['database']['ref'] ?? null) === null) {
            $this->step('secrets', 'failed', 'The external database reference ('.($config['database']['ref'] ?? 'none').') cannot be resolved.');

            return $this->finish(ExitCode::PREREQUISITES, $target);
        }

        foreach ((new ConfigValidator)->unresolved($config, fn (string $name) => $this->secrets?->resolve("env:{$name}")) as $missing) {
            $this->warnings[] = $missing.' (pending configuration)';
        }

        $this->step('secrets', 'ok', 'Required references resolved (values are never printed)');

        // 3. Prerequisites...
        $install = ! $input->getOption('skip-install');
        $services = (bool) $input->getOption('start-services');
        $checks = (new Prerequisites($this->runner))->check($config, $install, $services);
        $failed = array_values(array_filter($checks, fn (array $check) => $check['required'] && ! $check['ok']));

        if ($failed !== []) {
            $this->step('prerequisites', 'failed', implode(', ', array_map(fn (array $check) => "{$check['name']}: {$check['detail']}", $failed)));

            return $this->finish(ExitCode::PREREQUISITES, $target, ['prerequisites' => $checks]);
        }

        $this->step('prerequisites', 'ok', implode(', ', array_map(fn (array $check) => "{$check['name']} ".($check['ok'] ? 'ok' : 'missing (optional)'), array_values(array_filter($checks, fn (array $check) => ! str_starts_with($check['name'], 'ext-'))))));

        // 4. Target...
        if (is_dir($target) && (array_diff(scandir($target) ?: [], ['.', '..']) !== []) && ! $input->getOption('force')) {
            $this->step('target', 'failed', "{$target} exists and is not empty (use --force)");

            return $this->finish(ExitCode::TARGET_EXISTS, $target);
        }

        $this->step('target', 'ok', $target);

        $docker = ($config['database']['mode'] ?? 'docker') === 'docker';
        $database = ! $docker || $services;
        $artisan = $install && ! $input->getOption('skip-migrate') && $database;
        $noArtisan = ! $install ? 'skipped (--skip-install)' : ($input->getOption('skip-migrate') ? 'skipped (--skip-migrate)' : 'skipped: start PostgreSQL (or pass --start-services), then run php artisan migrate --seed && php artisan starter:config-apply');
        $app = "{$target}/apps/platform";

        /** @var list<array{0: string, 1: bool, 2: list<string>, 3: string, 4: string}> $tasks name, enabled, command, cwd, reason when skipped */
        $tasks = [
            ['composer', $install, ['composer', 'install', '--no-interaction', '--prefer-dist', '--no-progress'], $app, 'skipped (--skip-install)'],
            ['pnpm', $install, ['pnpm', 'install', '--frozen-lockfile'], $target, 'skipped (--skip-install)'],
            ['build', $install, ['pnpm', 'build'], $target, 'skipped (--skip-install)'],
            ['services', $services && $docker, ['docker', 'compose', '-f', 'docker/compose.yaml', '-f', 'docker/compose.dev.yaml', '--profile', 'local-db', '--profile', 'dev-mail', 'up', '-d', '--wait', 'postgres', 'mailpit'], $target, $docker ? 'not started (pass --start-services to allow Docker)' : 'not needed (external database)'],
            ['migrate', $artisan, [PHP_BINARY, 'artisan', 'migrate', '--seed', '--force', '--no-interaction'], $app, $noArtisan],
            ['apply', $artisan, [PHP_BINARY, 'artisan', 'starter:config-apply', '../../starter.config.json', '--no-interaction'], $app, $noArtisan],
            ['doctor', $artisan, [PHP_BINARY, 'artisan', 'starter:doctor', '--no-interaction'], $app, $noArtisan],
        ];

        $describe = fn (array $command) => implode(' ', array_map(fn (string $part) => $part === PHP_BINARY ? 'php' : $part, $command));

        if ($dry) {
            $this->step('template', 'planned', $this->templateDescription());
            $this->step('environment', 'planned', 'apps/platform/.env (APP_KEY and local secrets, mode 0600), docker/.env and starter.config.json');

            foreach ($tasks as [$name, $enabled, $command, , $skipped]) {
                $this->step($name, $enabled ? 'planned' : 'skipped', $enabled ? $describe($command) : $skipped);
            }

            return $this->finish(ExitCode::OK, $target, ['dry_run' => true]);
        }

        // 5. Template...
        try {
            $files = $this->template($target);
            $this->step('template', 'ok', "{$files} files from ".$this->templateDescription());
        } catch (TemplateException $exception) {
            $this->step('template', 'failed', $exception->getMessage());

            return $this->finish(ExitCode::TEMPLATE, $target);
        }

        // 6. Environment...
        $this->environment($config, $target);
        $this->step('environment', 'ok', 'apps/platform/.env, docker/.env and starter.config.json written');

        // 7. Tools, in order; the first failure stops the installation...
        foreach ($tasks as [$name, $enabled, $command, $cwd, $skipped]) {
            if (! $enabled) {
                $this->step($name, 'skipped', $skipped);

                continue;
            }

            $this->progress($name, $describe($command));
            $result = $this->runner->run($command, $cwd, [], $this->streaming() ? fn (string $chunk) => $this->output->write($chunk) : null);

            if (! $result['ok']) {
                $this->step($name, 'failed', $this->tail($result['output']));

                return $this->finish(ExitCode::STEP_FAILED, $target, ['failed_step' => $name]);
            }

            $this->step($name, 'ok', $describe($command));
        }

        return $this->finish(ExitCode::OK, $target, ['next_steps' => [
            "cd {$directory}/apps/platform && composer dev",
            'Open '.$this->input->getOption('url').' and sign in with a code sent to the owner email (Mailpit: http://localhost:8025)',
        ]]);
    }

    /**
     * The configuration: a file, interactive questions or safe defaults.
     *
     * @return array<string, mixed>
     */
    protected function configuration(string $name): array
    {
        $path = $this->input->getOption('config');

        if (is_string($path) && $path !== '') {
            $result = (new ConfigValidator)->validateFile($path);

            if ($result['config'] === null) {
                throw new \RuntimeException(implode('; ', $result['errors']));
            }

            return $result['config'];
        }

        $config = [
            'schemaVersion' => 1,
            'project' => ['name' => $name, 'locale' => 'es', 'timezone' => 'America/Santiago'],
            'features' => ['organizations' => false, 'ai' => true, 'rag' => false, 'postgrest' => false],
            'auth' => ['method' => 'email-otp', 'registration' => 'invite-only'],
            'database' => ['mode' => 'docker'],
            'mail' => ['transport' => 'mailpit'],
        ];

        if (! $this->input->isInteractive() || $this->input->getOption('json')) {
            return $config;
        }

        $helper = new QuestionHelper;
        $ask = fn (Question $question) => $helper->ask($this->input, $this->output, $question);

        $config['project']['name'] = (string) $ask(new Question("Project name [{$name}]: ", $name));
        $config['project']['locale'] = (string) $ask(new ChoiceQuestion('Language [es]: ', ['es', 'en'], 'es'));
        $email = $ask(new Question('Owner email (blank to create it later): '));

        if (is_string($email) && $email !== '') {
            $config['owner'] = ['email' => $email];
        }

        $config['database']['mode'] = (string) $ask(new ChoiceQuestion('PostgreSQL [docker]: ', ['docker', 'external'], 'docker'));

        if ($config['database']['mode'] === 'external') {
            $config['database']['ref'] = (string) $ask(new Question('Reference to the connection URL (env:NAME or file:/path) [env:DB_URL]: ', 'env:DB_URL'));
        }

        $config['features']['rag'] = (bool) $ask(new ConfirmationQuestion('Enable knowledge (RAG with pgvector)? [no] ', false));

        if ($config['features']['rag']) {
            $config['rag'] = ['mode' => 'internal', 'embeddingProfileRef' => 'default'];
        }

        return $config;
    }

    protected function templateDescription(): string
    {
        $template = $this->input->getOption('template');
        $archive = $this->input->getOption('archive');

        return match (true) {
            is_string($template) && $template !== '' => "local checkout {$template}",
            is_string($archive) && $archive !== '' => "archive {$archive} (SHA-256 verified)",
            default => TemplateSource::releaseUrl((string) ($this->input->getOption('release') ?? $this->version)).' (SHA-256 verified)',
        };
    }

    protected function template(string $target): int
    {
        $source = new TemplateSource($this->runner);
        $template = $this->input->getOption('template');
        $archive = $this->input->getOption('archive');
        $sha256 = $this->input->getOption('sha256');

        if (! is_dir($target)) {
            mkdir($target, 0755, true);
        }

        if (is_string($template) && $template !== '') {
            return $source->copyDirectory($template, $target);
        }

        if (is_string($archive) && $archive !== '') {
            if (! is_file($archive)) {
                throw new TemplateException("The archive {$archive} does not exist.");
            }

            $checksum = is_string($sha256) ? $sha256 : (is_file("{$archive}.sha256") ? (string) strtok(trim((string) file_get_contents("{$archive}.sha256")), " \t") : '');

            return $source->extractArchive($archive, $checksum, $target);
        }

        return $source->download(TemplateSource::releaseUrl((string) ($this->input->getOption('release') ?? $this->version)), is_string($sha256) ? $sha256 : null, $target);
    }

    /**
     * Write apps/platform/.env (mode 0600) and starter.config.json (which
     * never contains secrets).
     *
     * @param  array<string, mixed>  $config
     */
    protected function environment(array $config, string $target): void
    {
        $app = "{$target}/apps/platform";
        $env = EnvFile::fromFile("{$app}/.env.example");
        $features = (array) ($config['features'] ?? []);
        $locale = strtolower(substr((string) ($config['project']['locale'] ?? 'es'), 0, 2));

        $env->set('APP_NAME', (string) $config['project']['name'])
            ->set('APP_ENV', 'local')
            ->set('APP_DEBUG', true)
            ->set('APP_URL', (string) $this->input->getOption('url'))
            ->set('APP_KEY', 'base64:'.base64_encode(random_bytes(32)))
            ->set('APP_LOCALE', in_array($locale, ['es', 'en'], true) ? $locale : 'es')
            ->set('STARTER_AUTH_METHOD', (string) ($config['auth']['method'] ?? 'email-otp'))
            ->set('STARTER_REGISTRATION', (string) ($config['auth']['registration'] ?? 'invite-only'));

        if (isset($config['project']['timezone'])) {
            $env->set('STARTER_DISPLAY_TIMEZONE', (string) $config['project']['timezone']);
        }

        foreach (['organizations', 'ai', 'rag', 'postgrest'] as $feature) {
            $env->set('STARTER_FEATURE_'.strtoupper($feature), (bool) ($features[$feature] ?? false));
        }

        // Database: local Docker service with a generated password, or the
        // URL of the external reference...
        if (($config['database']['mode'] ?? 'docker') === 'external') {
            $env->set('DB_URL', (string) $this->secrets?->resolve($config['database']['ref'] ?? null));
        } else {
            $env->set('DB_HOST', '127.0.0.1')->set('DB_PASSWORD', bin2hex(random_bytes(16)));
        }

        // Mail...
        $transport = $config['mail']['transport'] ?? 'mailpit';
        $credential = $this->secrets?->resolve($config['mail']['credentialRef'] ?? null);

        match ($transport) {
            'log' => $env->set('MAIL_MAILER', 'log'),
            'smtp' => $env->set('MAIL_MAILER', 'smtp')->set('MAIL_URL', $credential),
            'resend' => $env->set('MAIL_MAILER', 'resend')->set('RESEND_API_KEY', $credential),
            'postmark' => $env->set('MAIL_MAILER', 'postmark')->set('POSTMARK_API_KEY', $credential),
            'mailgun' => $env->set('MAIL_MAILER', 'mailgun')->set('MAILGUN_SECRET', $credential)
                ->set('MAILGUN_DOMAIN', isset($config['mail']['domain']) ? (string) $config['mail']['domain'] : null)
                ->set('MAILGUN_ENDPOINT', ($config['mail']['region'] ?? 'us') === 'eu' ? 'api.eu.mailgun.net' : 'api.mailgun.net'),
            default => $env->set('MAIL_MAILER', 'smtp')->set('MAIL_HOST', '127.0.0.1')->set('MAIL_PORT', 1025),
        };

        if (isset($config['mail']['from'])) {
            $env->set('MAIL_FROM_ADDRESS', (string) $config['mail']['from']);
        }

        if (isset($config['mail']['fromName'])) {
            $env->set('MAIL_FROM_NAME', (string) $config['mail']['fromName']);
        }

        // AI keys referenced by env: are copied into the project .env (the
        // connections keep the reference, never the value)...
        $connections = (array) ($config['ai']['connections'] ?? []);

        foreach ($connections as $connection) {
            $reference = $connection['credentialRef'] ?? null;

            if (is_string($reference) && str_starts_with($reference, 'env:') && ($value = $this->secrets?->resolve($reference)) !== null) {
                $env->set(substr($reference, 4), $value);
            }
        }

        $real = array_filter($connections, fn ($connection) => ($connection['driver'] ?? null) !== 'simulated');
        $env->set('STARTER_AI_SIMULATED', $real === [] || count($real) < count($connections));

        if ($features['postgrest'] ?? false) {
            $env->set('POSTGREST_JWT_SECRET', bin2hex(random_bytes(32)))->set('POSTGREST_DB_PASSWORD', bin2hex(random_bytes(16)));
        }

        $env->save("{$app}/.env");

        // Docker Compose reads docker/.env for its interpolation: the same
        // generated database password and PostgREST secrets...
        $compose = new EnvFile('# Generated by laravel-starter; read by docker compose -f docker/compose.yaml.');

        foreach (['DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD', 'POSTGREST_JWT_SECRET', 'POSTGREST_DB_PASSWORD'] as $key) {
            if (($value = $env->get($key)) !== null && $value !== '') {
                $compose->set($key, $value);
            }
        }

        if (is_dir("{$target}/docker")) {
            $compose->save("{$target}/docker/.env");
        }

        $export = $config;
        $export['$schema'] = './tooling/contracts/starter.config.schema.json';
        file_put_contents("{$target}/starter.config.json", json_encode($export, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");
    }

    protected function streaming(): bool
    {
        return ! $this->input->getOption('json') && $this->output->isVerbose();
    }

    protected function progress(string $step, string $command): void
    {
        if (! $this->input->getOption('json')) {
            $this->io->writeln("<comment>›</comment> {$step}: <info>{$command}</info>");
        }
    }

    protected function step(string $step, string $status, string $detail): void
    {
        $this->steps[] = ['step' => $step, 'status' => $status, 'detail' => $detail];
    }

    protected function tail(string $output): string
    {
        $lines = array_slice(array_filter(explode("\n", trim($output))), -8);

        return implode("\n", $lines) ?: 'no output';
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    protected function finish(int $code, string $target, array $extra = []): int
    {
        $result = [
            'ok' => $code === ExitCode::OK,
            'exit_code' => $code,
            'meaning' => ExitCode::descriptions()[$code] ?? 'Unknown',
            'project' => $target,
            'steps' => $this->steps,
            'warnings' => array_values(array_unique($this->warnings)),
            ...$extra,
        ];

        if ($this->input->getOption('json')) {
            $this->output->writeln((string) json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return $code;
        }

        $this->io->table(['Step', 'Status', 'Detail'], array_map(fn (array $step) => [$step['step'], strtoupper($step['status']), $step['detail']], $this->steps));

        foreach ($result['warnings'] as $warning) {
            $this->io->warning($warning);
        }

        foreach ((array) ($extra['errors'] ?? []) as $error) {
            $this->io->error($error);
        }

        if (isset($extra['error'])) {
            $this->io->error((string) $extra['error']);
        }

        $code === ExitCode::OK
            ? $this->io->success(($extra['dry_run'] ?? false) ? 'Plan ready (nothing was created).' : 'Project created.')
            : $this->io->error("Stopped with exit code {$code}: {$result['meaning']}.");

        foreach ((array) ($extra['next_steps'] ?? []) as $next) {
            $this->io->writeln("  {$next}");
        }

        return $code;
    }
}
