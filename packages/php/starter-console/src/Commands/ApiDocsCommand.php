<?php

namespace Starter\Console\Commands;

use Illuminate\Console\Command;
use Starter\Console\Contracts\ContractChecks;

class ApiDocsCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'starter:api-docs
        {--check : Fail when the committed OpenAPI or Markdown reference differs from the code}';

    /**
     * @var string
     */
    protected $description = 'Export the OpenAPI contract (tooling/contracts/openapi.json) and its Markdown reference (docs/api-reference.md)';

    public function handle(ContractChecks $contracts): int
    {
        $openApi = $contracts->exportOpenApi();
        $markdown = $contracts->markdown($openApi);

        if ($this->option('check')) {
            $current = is_file($contracts->openApiPath()) ? json_decode((string) file_get_contents($contracts->openApiPath()), true) : null;
            $reference = is_file($contracts->markdownPath()) ? (string) file_get_contents($contracts->markdownPath()) : null;
            $outdated = array_keys(array_filter([
                'tooling/contracts/openapi.json' => $current != json_decode($openApi, true),
                'docs/api-reference.md' => $reference !== $markdown,
            ]));

            if ($outdated !== []) {
                $this->components->error('Outdated: '.implode(', ', $outdated).'. Run `php artisan starter:api-docs` and `pnpm api:client:generate`.');

                return self::FAILURE;
            }

            $this->components->info('The OpenAPI contract and the API reference match the code.');

            return self::SUCCESS;
        }

        file_put_contents($contracts->openApiPath(), $openApi);
        file_put_contents($contracts->markdownPath(), $markdown);

        $operations = substr_count($markdown, "\n### ");
        $this->components->info("OpenAPI written to tooling/contracts/openapi.json and the reference ({$operations} operations) to docs/api-reference.md.");
        $this->components->bulletList(['Regenerate the typed client: pnpm api:client:generate']);

        return self::SUCCESS;
    }
}
