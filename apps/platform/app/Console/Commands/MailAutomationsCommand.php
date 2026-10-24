<?php

namespace App\Console\Commands;

use App\Mail\Automation\MailAutomations;
use Illuminate\Console\Command;

/**
 * Queues the due inactivity and scheduled emails. The scheduler runs it
 * every five minutes; delivery keys make repeated runs harmless.
 */
class MailAutomationsCommand extends Command
{
    protected $signature = 'starter:mail-automations';

    protected $description = 'Queue the due emails of the inactivity and scheduled mail automations';

    public function handle(MailAutomations $automations): int
    {
        $counts = $automations->runDue();

        $this->components->info("Queued {$counts['inactivity']} inactivity and {$counts['scheduled']} scheduled emails.");

        return self::SUCCESS;
    }
}
