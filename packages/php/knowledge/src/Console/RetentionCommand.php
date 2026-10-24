<?php

namespace Starter\Knowledge\Console;

use Illuminate\Console\Command;
use Starter\Knowledge\Pipeline\Retention;

/**
 * Scheduler task (hourly, one server): applies the version retention of
 * the collections that define one.
 */
class RetentionCommand extends Command
{
    protected $signature = 'starter:rag-retention';

    protected $description = 'Delete document versions older than the retention of their collection';

    public function handle(Retention $retention): int
    {
        $purged = $retention->purge();

        $this->components->info("Purged {$purged} old document versions.");

        return self::SUCCESS;
    }
}
