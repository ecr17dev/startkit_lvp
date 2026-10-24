<?php

namespace Starter\Ai\Console;

use Illuminate\Console\Command;
use Starter\Ai\Catalog\CatalogManager;
use Starter\Ai\Connections\ErrorClassifier;
use Starter\Ai\Models\Connection;
use Throwable;

class SyncModelsCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'starter:ai-models-sync
        {--connection=* : Connection names or ids (default: every active connection)}
        {--json : Output the result as JSON}';

    /**
     * @var string
     */
    protected $description = 'List the models of the AI connections into the catalog (new models start disabled)';

    public function handle(CatalogManager $catalog): int
    {
        $filter = (array) $this->option('connection');
        $connections = Connection::query()
            ->active()
            ->when($filter !== [], fn ($query) => $query->where(fn ($query) => $query
                ->whereIn('name', $filter)
                ->orWhereIn('id', array_filter($filter, 'ctype_digit'))))
            ->orderBy('id')
            ->get();

        $results = [];

        foreach ($connections as $connection) {
            try {
                $results[] = ['connection' => $connection->name, 'status' => 'ok', ...$catalog->discover($connection)];
            } catch (Throwable $exception) {
                $results[] = ['connection' => $connection->name, 'status' => 'failed', 'error' => __(ErrorClassifier::classify($exception)->message()).' '.ErrorClassifier::safeMessage($exception)];
            }
        }

        $failed = collect($results)->contains('status', 'failed') || $connections->isEmpty();

        if ($this->option('json')) {
            $this->line((string) json_encode(['connections' => $results], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return $failed ? self::FAILURE : self::SUCCESS;
        }

        if ($connections->isEmpty()) {
            $this->components->warn('No active AI connection matches.');
        }

        foreach ($results as $result) {
            $result['status'] === 'ok'
                ? $this->components->twoColumnDetail($result['connection'], "{$result['total']} models · {$result['created']} new (disabled) · {$result['updated']} updated")
                : $this->components->twoColumnDetail($result['connection'], '<fg=red>FAILED</> '.$result['error']);
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
