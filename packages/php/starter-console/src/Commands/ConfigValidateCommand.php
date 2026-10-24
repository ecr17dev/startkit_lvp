<?php

namespace Starter\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Env;
use Starter\Installer\Config\ConfigValidator;

class ConfigValidateCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'starter:config-validate
        {path? : starter.config.json to validate (default: the project file, else the example)}
        {--strict : Also fail on warnings (pending credentials, unresolved references)}
        {--json : Output the result as JSON}';

    /**
     * @var string
     */
    protected $description = 'Validate starter.config.json: schema, plain secrets, cross rules and secret references';

    public function handle(): int
    {
        $path = $this->argument('path') ?: $this->defaultPath();
        $validator = new ConfigValidator;
        $result = $validator->validateFile($path);
        $warnings = $result['warnings'];

        if ($result['config'] !== null && $result['valid']) {
            array_push($warnings, ...$validator->unresolved($result['config'], fn (string $name) => is_scalar($value = Env::get($name)) ? (string) $value : null));
            array_push($warnings, ...$this->differences($result['config']));
        }

        $passed = $result['valid'] && (! $this->option('strict') || $warnings === []);

        if ($this->option('json')) {
            $this->line((string) json_encode([
                'path' => $path,
                'valid' => $result['valid'],
                'passed' => $passed,
                'errors' => $result['errors'],
                'warnings' => $warnings,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return $passed ? self::SUCCESS : self::FAILURE;
        }

        $this->components->info("Validating {$path}");

        foreach ($result['errors'] as $error) {
            $this->components->error($error);
        }

        foreach ($warnings as $warning) {
            $this->components->warn($warning);
        }

        $passed
            ? $this->components->info($warnings === [] ? 'The configuration is valid.' : 'The configuration is valid, with pending items.')
            : $this->components->error('The configuration is not valid.');

        return $passed ? self::SUCCESS : self::FAILURE;
    }

    protected function defaultPath(): string
    {
        foreach ([base_path('starter.config.json'), base_path('../../starter.config.json')] as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return base_path('../../starter.config.example.json');
    }

    /**
     * Feature flags of the file that differ from the running application.
     *
     * @param  array<string, mixed>  $config
     * @return list<string>
     */
    protected function differences(array $config): array
    {
        $differences = [];

        foreach ((array) ($config['features'] ?? []) as $feature => $enabled) {
            $current = config("starter.features.{$feature}");

            if ($current !== null && (bool) $current !== $enabled) {
                $differences[] = "/features/{$feature}: the file says ".json_encode($enabled).' but the application runs with '.json_encode((bool) $current);
            }
        }

        $method = $config['auth']['method'] ?? null;

        if ($method !== null && $method !== config('starter.auth.method')) {
            $differences[] = "/auth/method: the file says \"{$method}\" but the application uses \"".config('starter.auth.method').'"';
        }

        return $differences;
    }
}
