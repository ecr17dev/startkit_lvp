<?php

namespace Starter\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Starter\Administration\Health\HealthCheck;
use Starter\Administration\Health\HealthChecker;
use Throwable;

class DoctorCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'starter:doctor {--json : Output the diagnosis as JSON}';

    /**
     * @var string
     */
    protected $description = 'Diagnose the environment, configuration and services of the starter';

    /**
     * PHP extensions required by the starter.
     *
     * @var list<string>
     */
    protected array $extensions = ['pdo_pgsql', 'mbstring', 'openssl', 'intl', 'fileinfo', 'ctype', 'tokenizer', 'xml', 'zip'];

    public function handle(HealthChecker $checker): int
    {
        $rows = [];

        $rows[] = $this->row('php', version_compare(PHP_VERSION, '8.3.0', '>=') ? HealthCheck::OK : HealthCheck::FAILED, PHP_VERSION, true);

        foreach ($this->extensions as $extension) {
            $rows[] = $this->row("ext-{$extension}", extension_loaded($extension) ? HealthCheck::OK : HealthCheck::FAILED, null, true);
        }

        $rows[] = $this->row('app-key', filled(config('app.key')) ? HealthCheck::OK : HealthCheck::FAILED, null, true);
        $rows[] = $this->row('db-driver', DB::connection()->getDriverName() === 'pgsql' ? HealthCheck::OK : HealthCheck::WARNING, DB::connection()->getDriverName().' (PostgreSQL is the supported database)', false);
        $rows[] = $this->row('session-driver', config('session.driver') === 'database' ? HealthCheck::OK : HealthCheck::WARNING, (string) config('session.driver'), false);
        $rows[] = $this->row('queue-driver', config('queue.default') === 'database' ? HealthCheck::OK : HealthCheck::WARNING, (string) config('queue.default'), false);
        $rows[] = $this->row('mail', in_array(config('mail.default'), ['log', 'array'], true) ? HealthCheck::WARNING : HealthCheck::OK, (string) config('mail.default').' (login codes are sent by email)', false);
        $rows[] = $this->row('pgvector', ...$this->pgvector());

        foreach ((array) config('starter.features', []) as $feature => $enabled) {
            $rows[] = $this->row("feature:{$feature}", HealthCheck::OK, $enabled ? 'enabled' : 'disabled', false);
        }

        foreach ($checker->run() as $check) {
            $rows[] = $this->row("health:{$check->name}", $check->status, $check->message, $check->required);
        }

        $healthy = collect($rows)->every(fn (array $row) => ! ($row['required'] && $row['status'] === HealthCheck::FAILED));

        if ($this->option('json')) {
            $this->line((string) json_encode(['healthy' => $healthy, 'checks' => $rows], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $healthy ? self::SUCCESS : self::FAILURE;
        }

        $this->table(['Check', 'Status', 'Required', 'Detail'], array_map(fn (array $row) => [
            $row['name'], $row['status'], $row['required'] ? 'yes' : 'no', $row['detail'],
        ], $rows));

        $healthy
            ? $this->components->info('The starter looks healthy.')
            : $this->components->error('Some required checks failed.');

        return $healthy ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @return array{0: string, 1: string|null, 2: bool}
     */
    protected function pgvector(): array
    {
        $required = (bool) config('starter.features.rag');

        try {
            if (DB::connection()->getDriverName() !== 'pgsql') {
                return [$required ? HealthCheck::FAILED : HealthCheck::SKIPPED, 'requires PostgreSQL', $required];
            }

            $installed = DB::selectOne("select extversion from pg_extension where extname = 'vector'");

            if ($installed !== null) {
                return [HealthCheck::OK, 'installed '.$installed->extversion, $required];
            }

            $available = DB::selectOne("select default_version from pg_available_extensions where name = 'vector'");

            return [
                $required ? HealthCheck::FAILED : HealthCheck::WARNING,
                $available !== null ? "available {$available->default_version}, not installed" : 'not available on this server',
                $required,
            ];
        } catch (Throwable $e) {
            return [HealthCheck::FAILED, $e->getMessage(), $required];
        }
    }

    /**
     * @return array{name: string, status: string, detail: string|null, required: bool}
     */
    protected function row(string $name, string $status, ?string $detail = null, bool $required = false): array
    {
        return ['name' => $name, 'status' => $status, 'detail' => $detail, 'required' => $required];
    }
}
