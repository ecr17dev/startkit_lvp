<?php

namespace Starter\Ai\Console;

use Illuminate\Console\Command;
use Starter\Ai\Budgets\BudgetManager;
use Starter\Ai\Enums\RequestStatus;
use Starter\Ai\Ledger\UsageLedger;
use Starter\Ai\Models\AiRequest;

/**
 * Close requests whose stream never completed and release expired budget
 * reservations.
 */
class MaintainLedgerCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'starter:ai-maintain {--minutes=15 : Minutes after which a running request is interrupted}';

    /**
     * @var string
     */
    protected $description = 'Mark interrupted AI requests and release expired budget reservations';

    public function handle(UsageLedger $ledger, BudgetManager $budgets): int
    {
        $interrupted = 0;

        AiRequest::query()
            ->where('status', RequestStatus::Running->value)
            ->where('started_at', '<', now()->subMinutes(max(1, (int) $this->option('minutes'))))
            ->chunkById(100, function ($requests) use ($ledger, &$interrupted) {
                foreach ($requests as $request) {
                    $ledger->interrupt($request);
                    $interrupted++;
                }
            }, 'id');

        $released = $budgets->releaseExpired();

        $this->components->info("Interrupted requests: {$interrupted}. Released reservations: {$released}.");

        return self::SUCCESS;
    }
}
