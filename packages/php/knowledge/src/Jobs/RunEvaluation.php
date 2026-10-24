<?php

namespace Starter\Knowledge\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Starter\Knowledge\Evaluation\Evaluator;
use Starter\Knowledge\Models\EvaluationRun;

class RunEvaluation implements ShouldQueue
{
    use Queueable;

    public int $timeout = 1800;

    public int $tries = 1;

    public function __construct(public string $runId)
    {
        if (is_string($queue = config('starter.rag.indexing.queue')) && $queue !== '') {
            $this->onQueue($queue);
        }
    }

    public function handle(Evaluator $evaluator): void
    {
        $run = EvaluationRun::query()->with('set')->find($this->runId);

        if ($run !== null && $run->status === EvaluationRun::QUEUED) {
            $evaluator->run($run);
        }
    }
}
