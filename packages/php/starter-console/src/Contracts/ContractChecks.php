<?php

namespace Starter\Console\Contracts;

use Illuminate\Support\Facades\Artisan;
use Starter\Console\Docs\OpenApiMarkdown;
use Starter\Installer\Config\ConfigValidator;
use Starter\Installer\JsonSchema\Validator;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Checks that the committed contracts match the code: the OpenAPI export,
 * its Markdown reference, the typed client, the configuration schema (and
 * the installer's copy), the example configuration and the manifest.
 */
class ContractChecks
{
    public const OK = 'ok';

    public const FAILED = 'failed';

    public const SKIPPED = 'skipped';

    public function root(string $path = ''): string
    {
        return base_path('../../'.ltrim($path, '/'));
    }

    public function openApiPath(): string
    {
        return $this->root('tooling/contracts/openapi.json');
    }

    public function markdownPath(): string
    {
        return $this->root('docs/api-reference.md');
    }

    /**
     * Export the OpenAPI document of the code (Scramble) as JSON text, in
     * the source language (messages are translated at runtime), so the
     * contract does not depend on the configured locale.
     */
    public function exportOpenApi(): string
    {
        $temporary = storage_path('framework/openapi-'.bin2hex(random_bytes(6)).'.json');
        $locale = app()->getLocale();

        try {
            app()->setLocale('en');
            Artisan::call('scramble:export', ['--path' => $temporary]);

            return (string) file_get_contents($temporary);
        } finally {
            app()->setLocale($locale);
            @unlink($temporary);
        }
    }

    public function markdown(string $openApi): string
    {
        return (new OpenApiMarkdown)->render((array) json_decode($openApi, true));
    }

    /**
     * @return list<array{name: string, status: string, message: string}>
     */
    public function run(): array
    {
        $checks = [];

        try {
            $openApi = $this->exportOpenApi();
            $checks[] = $this->same('openapi', $this->openApiPath(), $openApi, true, 'php artisan starter:api-docs');
            $checks[] = $this->same('api-reference', $this->markdownPath(), $this->markdown($openApi), false, 'php artisan starter:api-docs');
        } catch (Throwable $exception) {
            $checks[] = $this->check('openapi', self::FAILED, 'Export failed: '.$exception->getMessage());
        }

        $checks[] = $this->typedClient();

        $schema = $this->root('tooling/contracts/starter.config.schema.json');
        $checks[] = is_file($schema) && file_get_contents($schema) === file_get_contents(ConfigValidator::schemaPath())
            ? $this->check('config-schema', self::OK, 'The installer ships the same starter.config schema.')
            : $this->check('config-schema', self::FAILED, 'tooling/create-starter/resources/starter.config.schema.json differs from tooling/contracts; copy the contract again.');

        $example = (new ConfigValidator)->validateFile($this->root('starter.config.example.json'));
        $checks[] = $example['valid']
            ? $this->check('config-example', self::OK, 'starter.config.example.json is valid.')
            : $this->check('config-example', self::FAILED, implode('; ', $example['errors']));

        $checks[] = $this->manifest();

        return $checks;
    }

    /**
     * @param  list<array{name: string, status: string, message: string}>  $checks
     */
    public function passed(array $checks): bool
    {
        return collect($checks)->every(fn (array $check) => $check['status'] !== self::FAILED);
    }

    /**
     * @return array{name: string, status: string, message: string}
     */
    protected function same(string $name, string $path, string $expected, bool $json, string $fix): array
    {
        if (! is_file($path)) {
            return $this->check($name, self::FAILED, basename($path)." is missing. Run `{$fix}`.");
        }

        $current = (string) file_get_contents($path);
        $equal = $json ? json_decode($current, true) == json_decode($expected, true) : $current === $expected;

        return $equal
            ? $this->check($name, self::OK, basename($path).' matches the code.')
            : $this->check($name, self::FAILED, basename($path)." is outdated. Run `{$fix}`.");
    }

    /**
     * @return array{name: string, status: string, message: string}
     */
    protected function typedClient(): array
    {
        $directory = $this->root('packages/js/api-client');
        $binary = $directory.'/node_modules/.bin/openapi-typescript';

        if (! is_file($binary)) {
            return $this->check('api-client', self::SKIPPED, 'openapi-typescript is not installed (pnpm install).');
        }

        $temporary = storage_path('framework/schema-'.bin2hex(random_bytes(6)).'.d.ts');

        try {
            $process = new Process([$binary, $this->openApiPath(), '--output', $temporary], $directory, null, null, 120);
            $process->run();

            if (! $process->isSuccessful() || ! is_file($temporary)) {
                return $this->check('api-client', self::FAILED, 'openapi-typescript failed: '.trim($process->getErrorOutput()));
            }

            return file_get_contents($temporary) === file_get_contents($directory.'/src/schema.d.ts')
                ? $this->check('api-client', self::OK, 'The typed client matches openapi.json.')
                : $this->check('api-client', self::FAILED, 'packages/js/api-client is outdated. Run `pnpm api:client:generate`.');
        } finally {
            @unlink($temporary);
        }
    }

    /**
     * @return array{name: string, status: string, message: string}
     */
    protected function manifest(): array
    {
        $manifest = json_decode((string) @file_get_contents($this->root('starter.manifest.json')), true);
        $schema = json_decode((string) @file_get_contents($this->root('tooling/contracts/starter.manifest.schema.json')), true);

        if (! is_array($manifest) || ! is_array($schema)) {
            return $this->check('manifest', self::FAILED, 'starter.manifest.json or its schema is missing or invalid JSON.');
        }

        $errors = (new Validator)->validate($manifest, $schema);

        return $errors === []
            ? $this->check('manifest', self::OK, 'starter.manifest.json follows its schema.')
            : $this->check('manifest', self::FAILED, implode('; ', $errors));
    }

    /**
     * @return array{name: string, status: string, message: string}
     */
    protected function check(string $name, string $status, string $message): array
    {
        return ['name' => $name, 'status' => $status, 'message' => $message];
    }
}
