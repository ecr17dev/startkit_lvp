<?php

namespace Starter\Installer\Process;

use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Runs the external tools of the installation (composer, pnpm, php
 * artisan, docker). Replaceable in tests.
 */
class ProcessRunner
{
    /**
     * @param  list<string>  $command
     * @param  array<string, string>  $env
     * @return array{ok: bool, output: string}
     */
    public function run(array $command, string $cwd, array $env = [], ?callable $output = null, int $timeout = 1800): array
    {
        $process = new Process($command, $cwd, $env === [] ? null : $env, null, $timeout);
        $buffer = '';

        $process->run(function (string $type, string $chunk) use (&$buffer, $output) {
            $buffer .= $chunk;

            if ($output !== null) {
                $output($chunk);
            }
        });

        return ['ok' => $process->isSuccessful(), 'output' => $buffer];
    }

    public function find(string $binary): ?string
    {
        return (new ExecutableFinder)->find($binary);
    }

    /**
     * First line of `<binary> <flag>` (version probes), or null.
     */
    public function version(string $binary, string $flag = '--version'): ?string
    {
        $path = $this->find($binary);

        if ($path === null) {
            return null;
        }

        $process = new Process([$path, $flag], null, null, null, 30);
        $process->run();

        return $process->isSuccessful() ? trim(strtok($process->getOutput(), "\n") ?: '') : null;
    }
}
