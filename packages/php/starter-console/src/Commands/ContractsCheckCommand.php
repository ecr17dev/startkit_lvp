<?php

namespace Starter\Console\Commands;

use Illuminate\Console\Command;
use Starter\Console\Contracts\ContractChecks;

class ContractsCheckCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'starter:contracts-check {--json : Output the result as JSON}';

    /**
     * @var string
     */
    protected $description = 'Check that OpenAPI, the API reference, the typed client, the config schema/example and the manifest match the code';

    public function handle(ContractChecks $contracts): int
    {
        $checks = $contracts->run();
        $passed = $contracts->passed($checks);

        if ($this->option('json')) {
            $this->line((string) json_encode(['passed' => $passed, 'checks' => $checks], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return $passed ? self::SUCCESS : self::FAILURE;
        }

        foreach ($checks as $check) {
            $color = match ($check['status']) {
                ContractChecks::OK => 'green',
                ContractChecks::SKIPPED => 'yellow',
                default => 'red',
            };
            $this->components->twoColumnDetail($check['name'], "<fg={$color}>".strtoupper($check['status'])."</> {$check['message']}");
        }

        return $passed ? self::SUCCESS : self::FAILURE;
    }
}
