<?php

namespace Starter\Ai\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Starter\Ai\Lab\LabRunner;
use Starter\Ai\Models\LabRun;

/**
 * One lab worker: claims and runs pending tasks of a run until none is left.
 */
class RunLabTasks implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 1800;

    public function __construct(public int $runId) {}

    public function handle(LabRunner $runner): void
    {
        $run = LabRun::query()->find($this->runId);

        if ($run !== null && $run->isActive()) {
            $runner->work($run);
        }
    }
}
