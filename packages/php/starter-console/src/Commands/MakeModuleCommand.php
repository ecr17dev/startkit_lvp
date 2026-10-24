<?php

namespace Starter\Console\Commands;

use Illuminate\Console\Command;
use InvalidArgumentException;
use Starter\Console\Generators\Field;
use Starter\Console\Generators\ModuleGenerator;
use Starter\Console\Generators\ModuleSpec;
use Symfony\Component\Process\Process;

class MakeModuleCommand extends Command
{
    public const INVALID = 2;

    public const CONFLICT = 3;

    /**
     * @var string
     */
    protected $signature = 'starter:make-module
        {name? : Singular model name, e.g. Project}
        {--field=* : name:type[:modifier...]; types string, text, integer, decimal=P,S, boolean, date, datetime, enum=a|b|c, email, url; modifiers nullable, unique, index, max=N, min=N, label=..., label_es=..., options_es=a|b|c}
        {--spec= : JSON specification (name, fields, api, ai, labels, icon) instead of the options}
        {--no-api : Do not generate the JSON API}
        {--ai : Generate AI operator tools (list, create, update, delete)}
        {--label-es= : Spanish singular/plural, e.g. proyecto/proyectos}
        {--gender-es=m : Grammatical gender of the Spanish label (m or f)}
        {--icon=FolderKanban : Lucide icon of the sidebar entry}
        {--force : Overwrite existing files}
        {--no-format : Do not run Pint and the frontend formatter on the generated files}
        {--dry-run : Only list the files that would be written}
        {--json : Output the result as JSON}';

    /**
     * @var string
     */
    protected $description = 'Generate a complete module: migration, model, factory, policy, permissions, Actions, Form Request, Inertia pages, API, AI tools, docs and tests';

    public function handle(): int
    {
        try {
            $spec = $this->spec();
        } catch (InvalidArgumentException $exception) {
            return $this->reject($exception->getMessage(), self::INVALID);
        }

        $generator = new ModuleGenerator($spec);
        $files = $generator->files();
        $files = $this->reuseMigration($files, $spec);
        $specPath = "../../docs/modules/{$spec->slug()}.json";
        $files[$specPath] = json_encode($spec->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n";

        $conflicts = array_values(array_filter(array_keys($files), fn (string $path) => is_file(base_path($path))));
        $provider = "App\\Providers\\Modules\\{$spec->models()}ServiceProvider";
        $nextSteps = array_values(array_filter([
            'php artisan migrate',
            'php artisan starter:permissions',
            'php artisan wayfinder:generate --with-form (or pnpm build)',
            $spec->api ? 'php artisan starter:api-docs && pnpm api:client:generate' : null,
            "php artisan test --filter={$spec->models()}Test",
        ]));

        if ($conflicts !== [] && ! $this->option('force') && ! $this->option('dry-run')) {
            return $this->reject('These files already exist (use --force to overwrite): '.implode(', ', $conflicts), self::CONFLICT, ['conflicts' => $conflicts]);
        }

        if (! $this->option('dry-run')) {
            foreach ($files as $path => $contents) {
                $absolute = base_path($path);

                if (! is_dir(dirname($absolute))) {
                    mkdir(dirname($absolute), 0755, true);
                }

                file_put_contents($absolute, $contents);
            }

            $this->registerProvider($provider, $this->laravel->getBootstrapProvidersPath());
            $translations = $this->translate($spec->translations());
            $formatted = $this->option('no-format') ? [] : $this->format(array_keys($files));
        }

        $result = [
            'module' => $spec->toArray(),
            'dry_run' => (bool) $this->option('dry-run'),
            'files' => array_map(fn (string $path) => str_starts_with($path, '../../') ? substr($path, 6) : "apps/platform/{$path}", array_keys($files)),
            'overwritten' => $this->option('dry-run') ? [] : $conflicts,
            'existing' => $this->option('dry-run') ? $conflicts : [],
            'provider' => $provider,
            'translations_added' => $translations ?? 0,
            'formatted_with' => $formatted ?? [],
            'next_steps' => $nextSteps,
        ];

        if ($this->option('json')) {
            $this->line((string) json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $this->components->info(($this->option('dry-run') ? 'Would generate' : 'Generated')." the {$spec->plural} module ({$spec->model}).");
        $this->components->bulletList($result['files']);

        if (! $this->option('dry-run')) {
            $this->components->twoColumnDetail('Provider registered', $provider);
            $this->components->twoColumnDetail('Spanish translations added', (string) $result['translations_added']);
            $this->components->twoColumnDetail('Formatted with', $result['formatted_with'] === [] ? 'nothing (run composer lint and pnpm check:fix)' : implode(', ', $result['formatted_with']));
        }

        $this->components->info('Next steps:');
        $this->components->bulletList($nextSteps);

        return self::SUCCESS;
    }

    protected function spec(): ModuleSpec
    {
        $file = $this->option('spec');

        if (is_string($file) && $file !== '') {
            $data = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;

            if (! is_array($data)) {
                throw new InvalidArgumentException("The specification {$file} does not exist or is not valid JSON.");
            }

            return ModuleSpec::fromArray($data);
        }

        $name = (string) $this->argument('name');
        $fields = (array) $this->option('field');

        if ($name === '' || $fields === []) {
            throw new InvalidArgumentException('Pass a name and at least one --field (or --spec=module.json).');
        }

        [$singularEs, $pluralEs] = array_pad(explode('/', (string) $this->option('label-es')), 2, null);

        return new ModuleSpec(
            name: $name,
            fields: array_map(fn (string $definition) => Field::parse($definition), array_values(array_map('strval', $fields))),
            api: ! $this->option('no-api'),
            ai: (bool) $this->option('ai'),
            singularEs: $singularEs !== '' ? $singularEs : null,
            pluralEs: $pluralEs,
            genderEs: (string) $this->option('gender-es'),
            icon: (string) $this->option('icon'),
        );
    }

    /**
     * Keep the name of an existing migration of the same table, so a
     * regeneration overwrites it instead of creating a second one.
     *
     * @param  array<string, string>  $files
     * @return array<string, string>
     */
    protected function reuseMigration(array $files, ModuleSpec $spec): array
    {
        $existing = glob(database_path("migrations/*_create_{$spec->table()}_table.php")) ?: [];

        if ($existing === []) {
            return $files;
        }

        $renamed = [];

        foreach ($files as $path => $contents) {
            $renamed[str_ends_with($path, "_create_{$spec->table()}_table.php") ? 'database/migrations/'.basename($existing[0]) : $path] = $contents;
        }

        return $renamed;
    }

    /**
     * Format the generated files with the project tools when installed
     * (Pint for PHP, the Vite+ formatter for Vue/TypeScript).
     *
     * @param  list<string>  $paths
     * @return list<string> tools that ran successfully
     */
    protected function format(array $paths): array
    {
        $tools = [];
        $php = array_values(array_filter($paths, fn (string $path) => str_ends_with($path, '.php') && ! str_starts_with($path, '../')));
        $frontend = array_values(array_filter($paths, fn (string $path) => preg_match('/\.(vue|ts)$/', $path) === 1));

        foreach ([['pint', base_path('vendor/bin/pint'), $php], ['vp fmt', base_path('node_modules/.bin/vp'), $frontend]] as [$name, $binary, $files]) {
            if ($files === [] || ! is_file($binary)) {
                continue;
            }

            $command = $name === 'pint' ? [$binary, ...$files] : [$binary, 'fmt', ...$files];
            $process = new Process($command, base_path(), null, null, 120);
            $process->run();

            if ($process->isSuccessful()) {
                $tools[] = $name;
            }
        }

        return $tools;
    }

    /**
     * Register the module provider after the existing ones, keeping the
     * order, comments and imports of bootstrap/providers.php.
     */
    protected function registerProvider(string $provider, string $path): void
    {
        $contents = (string) file_get_contents($path);
        $basename = class_basename($provider);

        if (str_contains($contents, "use {$provider};") || str_contains($contents, "{$provider}::class")) {
            return;
        }

        preg_match_all('/^use [^;]+;$/m', $contents, $matches);
        $imports = [...$matches[0], "use {$provider};"];
        sort($imports);

        $contents = $matches[0] === []
            ? (string) preg_replace('/^<\?php\s*/', "<?php\n\n".implode("\n", $imports)."\n\n", $contents)
            : (string) preg_replace('/^use [^;]+;(\R^use [^;]+;)*/m', implode("\n", $imports), $contents, 1);

        $marker = '    // Modules generated with starter:make-module...';
        $entry = "    {$basename}::class,\n";

        $contents = str_contains($contents, $marker)
            ? (string) preg_replace('/\n\];\s*$/', "\n{$entry}];\n", $contents)
            : (string) preg_replace('/\n\];\s*$/', "\n\n{$marker}\n{$entry}];\n", $contents);

        file_put_contents($path, $contents);
    }

    /**
     * Add the Spanish translations that are missing (existing ones win).
     *
     * @param  array<string, string>  $strings
     */
    protected function translate(array $strings): int
    {
        $path = lang_path('es.json');
        $current = is_file($path) ? (array) json_decode((string) file_get_contents($path), true) : [];
        $missing = array_diff_key($strings, $current);

        if ($missing !== []) {
            file_put_contents($path, json_encode($current + $missing, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n");
        }

        return count($missing);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    protected function reject(string $message, int $code, array $extra = []): int
    {
        if ($this->option('json')) {
            $this->line((string) json_encode(['error' => $message, 'code' => $code, ...$extra], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            $this->components->error($message);
        }

        return $code;
    }
}
