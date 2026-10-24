<?php

namespace Starter\Console\Commands;

use Composer\InstalledVersions;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Throwable;

class ManifestCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'starter:manifest
        {--path= : Where to write the manifest (default: monorepo root starter.manifest.json)}
        {--check : Fail when the committed manifest differs from the installed versions}';

    /**
     * @var string
     */
    protected $description = 'Record the installed versions of the stack in starter.manifest.json';

    public function handle(): int
    {
        $path = $this->option('path') ?: base_path('../../starter.manifest.json');
        $current = is_file($path) ? (array) json_decode((string) file_get_contents($path), true) : [];

        $manifest = [
            '$schema' => './tooling/contracts/starter.manifest.schema.json',
            'starter' => $current['starter'] ?? ['version' => '0.1.0', 'laravel_branch' => '13.x'],
            'runtime' => [
                'php' => PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION,
                'php_minimum' => '8.3',
                'node' => $this->command(['node', '--version']),
                'pnpm' => $this->command(['pnpm', '--version']),
                'composer' => $this->composerVersion(),
            ],
            'composer' => $this->composerPackages([
                'laravel/framework', 'laravel/ai', 'laravel/fortify', 'laravel/sanctum', 'inertiajs/inertia-laravel',
                'laravel/wayfinder', 'spatie/laravel-permission', 'dedoc/scramble',
            ]),
            'npm' => $this->npmPackages([
                'vue', '@inertiajs/vue3', 'tailwindcss', 'reka-ui', 'vite-plus', 'typescript',
            ]),
            'ui' => [
                'shadcn-vue_style' => json_decode((string) file_get_contents(base_path('components.json')), true)['style'] ?? null,
            ],
            'services' => [
                'postgresql' => $this->postgresVersion(),
                'pgvector' => $this->pgvectorVersion(),
                'postgrest' => $current['services']['postgrest'] ?? 'v13.0.7 (docker image tag)',
            ],
            'reviewed_at' => now()->toDateString(),
        ];

        $json = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";

        if ($this->option('check')) {
            $stable = fn (array $data) => array_diff_key($data, ['reviewed_at' => true]);

            if ($stable($current) !== $stable((array) json_decode((string) $json, true))) {
                $this->components->error('starter.manifest.json is outdated. Run `php artisan starter:manifest`.');

                return self::FAILURE;
            }

            $this->components->info('starter.manifest.json is up to date.');

            return self::SUCCESS;
        }

        file_put_contents($path, $json);
        $this->components->info("Manifest written to {$path}");

        return self::SUCCESS;
    }

    /**
     * @param  list<string>  $packages
     * @return array<string, string|null>
     */
    protected function composerPackages(array $packages): array
    {
        $versions = [];

        foreach ($packages as $package) {
            $versions[$package] = InstalledVersions::isInstalled($package) ? InstalledVersions::getPrettyVersion($package) : null;
        }

        return $versions;
    }

    /**
     * @param  list<string>  $packages
     * @return array<string, string|null>
     */
    protected function npmPackages(array $packages): array
    {
        $versions = [];

        foreach ($packages as $package) {
            $file = base_path("node_modules/{$package}/package.json");
            $versions[$package] = is_file($file) ? (json_decode((string) file_get_contents($file), true)['version'] ?? null) : null;
        }

        return $versions;
    }

    protected function composerVersion(): ?string
    {
        $output = $this->command(['composer', '--version', '--no-ansi']);

        return $output !== null && preg_match('/Composer version (\S+)/', $output, $match) ? $match[1] : $output;
    }

    protected function postgresVersion(): ?string
    {
        try {
            return DB::connection()->getDriverName() === 'pgsql' ? (string) DB::selectOne('show server_version')?->server_version : null;
        } catch (Throwable) {
            return null;
        }
    }

    protected function pgvectorVersion(): ?string
    {
        try {
            $row = DB::selectOne("select extversion from pg_extension where extname = 'vector'");

            return $row?->extversion;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  list<string>  $command
     */
    protected function command(array $command): ?string
    {
        try {
            $process = new Process($command, base_path(), ['COMPOSER_ALLOW_SUPERUSER' => '1']);
            $process->setTimeout(15);
            $process->run();

            return $process->isSuccessful() ? trim($process->getOutput()) : null;
        } catch (Throwable) {
            return null;
        }
    }
}
