<?php

namespace App\Console\Commands;

use App\PostgRest\PostgRest;
use Illuminate\Console\Command;
use Throwable;

/**
 * Export the contract PostgREST generates for the api schema (its own
 * OpenAPI 2.0 document), kept apart from the Laravel OpenAPI document.
 */
class PostgRestContractCommand extends Command
{
    protected $signature = 'starter:postgrest-contract {--path= : Destination (default tooling/contracts/postgrest.json)}';

    protected $description = 'Export the PostgREST contract of the api schema';

    public function handle(PostgRest $postgrest): int
    {
        if (! $postgrest->enabled()) {
            $this->components->warn('PostgREST is disabled (STARTER_FEATURE_POSTGREST=false).');

            return self::SUCCESS;
        }

        try {
            $response = $postgrest->as(null)->withHeaders(['Accept' => 'application/openapi+json'])->get('');
        } catch (Throwable $exception) {
            $this->components->error('PostgREST could not be reached: '.$exception->getMessage());

            return self::FAILURE;
        }

        if (! $response->successful()) {
            $this->components->error('PostgREST answered '.$response->status().'.');

            return self::FAILURE;
        }

        $path = (string) ($this->option('path') ?: base_path('../../tooling/contracts/postgrest.json'));
        file_put_contents($path, json_encode($response->json(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL);

        $this->components->info("PostgREST contract exported to {$path}.");

        return self::SUCCESS;
    }
}
