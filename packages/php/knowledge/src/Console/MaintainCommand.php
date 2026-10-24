<?php

namespace Starter\Knowledge\Console;

use Illuminate\Console\Command;
use Starter\Knowledge\Enums\JobStatus;
use Starter\Knowledge\Enums\JobType;
use Starter\Knowledge\Enums\SourceStatus;
use Starter\Knowledge\Knowledge;
use Starter\Knowledge\Models\RagJob;
use Starter\Knowledge\Models\Source;
use Starter\Knowledge\Pipeline\JobRunner;
use Starter\Knowledge\Pipeline\RagJobs;

/**
 * Scheduler task (one server, no overlap): recovers jobs whose worker
 * died and dispatches the scheduled syncs that are due, idempotently.
 */
class MaintainCommand extends Command
{
    protected $signature = 'starter:rag-maintain';

    protected $description = 'Recover stale knowledge jobs and dispatch scheduled source syncs';

    public function handle(Knowledge $knowledge, RagJobs $jobs, JobRunner $runner): int
    {
        $recovered = $this->recover($jobs, $runner);
        $scheduled = $knowledge->available() && config('starter.rag.indexing.automatic') ? $this->schedule($jobs) : 0;

        $this->components->info("Recovered {$recovered} stale jobs; dispatched {$scheduled} scheduled syncs.");

        return self::SUCCESS;
    }

    protected function recover(RagJobs $jobs, JobRunner $runner): int
    {
        $stale = now()->subMinutes(max(1, (int) config('starter.rag.indexing.stale_after_minutes', 10)));
        $count = 0;

        $candidates = RagJob::query()
            ->where('status', JobStatus::Running->value)
            ->where('heartbeat_at', '<', $stale)
            ->whereRaw("NOT COALESCE((payload->>'waiting')::boolean, false)")
            ->limit(100)
            ->get();

        foreach ($candidates as $job) {
            if ($job->attempts >= JobRunner::MAX_ATTEMPTS) {
                $runner->markFailed($job->id, null);
            } else {
                $job->forceFill(['status' => JobStatus::Retrying, 'error' => __('The worker stopped responding; the job was queued again.')])->save();
                $jobs->dispatch($job);
            }

            $count++;
        }

        return $count;
    }

    protected function schedule(RagJobs $jobs): int
    {
        $count = 0;

        $due = Source::query()
            ->where('status', SourceStatus::Active->value)
            ->whereNotNull('schedule')
            ->where('next_sync_at', '<=', now())
            ->limit(50)
            ->get();

        foreach ($due as $source) {
            // Atomic: only one scheduler run moves the next date...
            $moved = Source::query()->whereKey($source->id)->where('next_sync_at', $source->next_sync_at)
                ->update(['next_sync_at' => $source->nextRunAfter(now())]);

            if ($moved !== 1 || $source->jobs()->where('type', JobType::SyncSource->value)->whereIn('status', JobStatus::open())->exists()) {
                continue;
            }

            $jobs->create(JobType::SyncSource, ['collection_id' => $source->collection_id, 'source_id' => $source->id, 'trigger' => 'schedule']);
            $count++;
        }

        return $count;
    }
}
