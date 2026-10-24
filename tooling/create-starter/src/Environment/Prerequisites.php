<?php

namespace Starter\Installer\Environment;

use Starter\Installer\Process\ProcessRunner;

/**
 * Checks the tools a project needs according to its configuration: PHP
 * and extensions, Composer, Node.js and pnpm (unless --skip-install) and
 * Docker only when the database runs in Docker and services may start.
 */
class Prerequisites
{
    public const PHP_MINIMUM = '8.3.0';

    public const NODE_MINIMUM = '22.12.0';

    public const EXTENSIONS = ['pdo_pgsql', 'mbstring', 'openssl', 'intl', 'fileinfo', 'ctype', 'tokenizer', 'xml', 'zip', 'phar'];

    public function __construct(protected ProcessRunner $runner) {}

    /**
     * @param  array<string, mixed>  $config
     * @return list<array{name: string, ok: bool, required: bool, detail: string}>
     */
    public function check(array $config, bool $install, bool $services): array
    {
        $checks = [
            $this->row('php', version_compare(PHP_VERSION, self::PHP_MINIMUM, '>='), true, PHP_VERSION.' (>= '.self::PHP_MINIMUM.')'),
        ];

        foreach (self::EXTENSIONS as $extension) {
            $checks[] = $this->row("ext-{$extension}", extension_loaded($extension), true, extension_loaded($extension) ? 'loaded' : 'missing');
        }

        $composer = $this->runner->version('composer');
        $checks[] = $this->row('composer', $composer !== null, $install, $composer ?? 'not found');

        $node = $this->runner->version('node');
        $nodeVersion = $node !== null ? ltrim($node, 'v') : null;
        $checks[] = $this->row('node', $nodeVersion !== null && version_compare($nodeVersion, self::NODE_MINIMUM, '>='), $install, $node !== null ? "{$node} (>= ".self::NODE_MINIMUM.')' : 'not found');

        $pnpm = $this->runner->version('pnpm');
        $checks[] = $this->row('pnpm', $pnpm !== null, $install, $pnpm ?? 'not found (corepack enable)');

        $docker = ($config['database']['mode'] ?? 'docker') === 'docker';
        $dockerVersion = $docker ? $this->runner->version('docker') : null;
        $checks[] = $this->row('docker', ! $docker || $dockerVersion !== null, $docker && $services, $docker ? ($dockerVersion ?? 'not found') : 'not needed (external database)');

        return $checks;
    }

    /**
     * @param  list<array{name: string, ok: bool, required: bool, detail: string}>  $checks
     */
    public static function passed(array $checks): bool
    {
        foreach ($checks as $check) {
            if ($check['required'] && ! $check['ok']) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array{name: string, ok: bool, required: bool, detail: string}
     */
    protected function row(string $name, bool $ok, bool $required, string $detail): array
    {
        return ['name' => $name, 'ok' => $ok, 'required' => $required, 'detail' => $detail];
    }
}
