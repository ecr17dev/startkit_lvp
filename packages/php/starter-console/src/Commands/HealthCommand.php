<?php

namespace Starter\Console\Commands;

use Illuminate\Console\Command;
use Starter\Administration\Health\HealthCheck;
use Starter\Administration\Health\HealthChecker;

class HealthCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'starter:health
        {--live : Only check that the application boots (container liveness)}
        {--json : Output the result as JSON}';

    /**
     * @var string
     */
    protected $description = 'Run the readiness checks used by /health/ready';

    public function handle(HealthChecker $checker): int
    {
        if ($this->option('live')) {
            return self::SUCCESS;
        }

        $checks = $checker->run();
        $ready = $checker->ready($checks);

        if ($this->option('json')) {
            $this->line((string) json_encode([
                'ready' => $ready,
                'checks' => array_map(fn (HealthCheck $check) => $check->toArray(), $checks),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $ready ? self::SUCCESS : self::FAILURE;
        }

        $this->table(['Check', 'Status', 'Required', 'Detail'], array_map(fn (HealthCheck $check) => [
            $check->name,
            $check->status,
            $check->required ? 'yes' : 'no',
            $check->message,
        ], $checks));

        $ready ? $this->components->info('Ready.') : $this->components->error('Not ready.');

        return $ready ? self::SUCCESS : self::FAILURE;
    }
}
