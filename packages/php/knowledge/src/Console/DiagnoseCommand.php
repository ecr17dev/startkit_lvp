<?php

namespace Starter\Knowledge\Console;

use Illuminate\Console\Command;
use Starter\Knowledge\Enums\JobStatus;
use Starter\Knowledge\Knowledge;
use Starter\Knowledge\Models\Collection;
use Starter\Knowledge\Models\Document;
use Starter\Knowledge\Models\RagConnection;
use Starter\Knowledge\Models\RagJob;

class DiagnoseCommand extends Command
{
    protected $signature = 'starter:rag-doctor
        {--connection= : RAG connection id or name (default: the active one)}
        {--all : Diagnose every connection}
        {--json : Print the report as JSON}';

    /**
     * @var list<string>
     */
    protected $aliases = ['starter:rag-diagnose'];

    protected $description = 'Check knowledge (RAG): mode, connections (PostgreSQL, TLS, pgvector, privileges, schema, search test) and the state of collections, documents and jobs';

    public function handle(Knowledge $knowledge): int
    {
        $connections = $this->connections($knowledge);

        if ($connections === []) {
            $this->components->error('No RAG connection matches.');

            return self::FAILURE;
        }

        $reports = array_map(fn (RagConnection $connection) => [
            'id' => $connection->id,
            'name' => $connection->name,
            'label' => $connection->label,
            'kind' => $connection->kind->value,
            ...$knowledge->diagnose($connection)->toArray(),
        ], $connections);

        $summary = [
            'feature_enabled' => $knowledge->featureEnabled(),
            'mode' => $knowledge->mode()->value,
            'available' => $knowledge->available(),
            'collections' => Collection::query()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status')->map(fn ($total) => (int) $total)->all(),
            'documents' => Document::query()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status')->map(fn ($total) => (int) $total)->all(),
            'jobs_open' => RagJob::query()->whereIn('status', JobStatus::open())->count(),
            'jobs_paused' => RagJob::query()->where('status', JobStatus::Paused)->count(),
            'jobs_failed_24h' => RagJob::query()->where('status', JobStatus::Failed)->where('updated_at', '>=', now()->subDay())->count(),
        ];

        // Enabled knowledge must be usable; a failing diagnosis always fails...
        $passed = collect($reports)->every(fn (array $report) => $report['passed'])
            && (! $summary['feature_enabled'] || $summary['mode'] === 'disabled' || $summary['available']);

        if ($this->option('json')) {
            $this->line((string) json_encode(['passed' => $passed, 'summary' => $summary, 'connections' => $reports], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return $passed ? self::SUCCESS : self::FAILURE;
        }

        $this->components->info('Knowledge: '.($summary['feature_enabled'] ? 'enabled' : 'disabled').' · mode: '.$summary['mode'].' · '.($summary['available'] ? 'available' : 'not available'));
        $this->components->twoColumnDetail('Collections', $this->counts($summary['collections']));
        $this->components->twoColumnDetail('Documents', $this->counts($summary['documents']));
        $this->components->twoColumnDetail('Jobs', "{$summary['jobs_open']} open · {$summary['jobs_paused']} paused · {$summary['jobs_failed_24h']} failed (24 h)");

        foreach ($reports as $report) {
            $this->newLine();
            $this->components->info("Connection #{$report['id']} {$report['label']} ({$report['kind']})");

            foreach ($report['checks'] as $check) {
                $this->components->twoColumnDetail($check['label'], strtoupper($check['status']).' · '.$check['message']);

                if (isset($check['sql'])) {
                    $this->line('  <comment>'.$check['sql'].'</comment>');
                }
            }
        }

        return $passed ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @return list<RagConnection>
     */
    protected function connections(Knowledge $knowledge): array
    {
        if ($this->option('all')) {
            $knowledge->internalConnection();

            return array_values(RagConnection::query()->orderBy('id')->get()->all());
        }

        $option = $this->option('connection');

        if (is_string($option) && $option !== '') {
            $connection = RagConnection::query()
                ->where('name', $option)
                ->when(ctype_digit($option), fn ($query) => $query->orWhere('id', (int) $option))
                ->first();

            return $connection !== null ? [$connection] : [];
        }

        return [$knowledge->connection() ?? $knowledge->internalConnection()];
    }

    /**
     * @param  array<string, int>  $counts
     */
    protected function counts(array $counts): string
    {
        return $counts === []
            ? 'none'
            : implode(' · ', array_map(fn (string $status, int $total) => "{$total} {$status}", array_keys($counts), $counts));
    }
}
