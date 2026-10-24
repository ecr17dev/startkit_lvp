<?php

namespace Starter\Knowledge\Console;

use Illuminate\Console\Command;
use Starter\Knowledge\Knowledge;

class DiagnoseCommand extends Command
{
    protected $signature = 'starter:rag-diagnose {--json : Print the report as JSON}';

    protected $description = 'Check the knowledge (RAG) connection: PostgreSQL, TLS, pgvector, privileges, schema and a search test';

    public function handle(Knowledge $knowledge): int
    {
        $connection = $knowledge->connection() ?? $knowledge->internalConnection();
        $report = $knowledge->diagnose($connection)->toArray();

        if ($this->option('json')) {
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return $report['passed'] ? self::SUCCESS : self::FAILURE;
        }

        $this->components->info('Mode: '.$knowledge->mode()->value.' · connection: '.$connection->label);

        foreach ($report['checks'] as $check) {
            $this->components->twoColumnDetail($check['label'], strtoupper($check['status']).' · '.$check['message']);

            if (isset($check['sql'])) {
                $this->line('  <comment>'.$check['sql'].'</comment>');
            }
        }

        return $report['passed'] ? self::SUCCESS : self::FAILURE;
    }
}
