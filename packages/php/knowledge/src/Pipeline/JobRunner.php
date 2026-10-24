<?php

namespace Starter\Knowledge\Pipeline;

use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Ai\Exceptions\FailoverableException;
use Starter\Ai\Exceptions\BudgetExceededException;
use Starter\Ai\Exceptions\ModelNotUsableException;
use Starter\Knowledge\Enums\JobStatus;
use Starter\Knowledge\Enums\JobType;
use Starter\Knowledge\Exceptions\JobCancelledException;
use Starter\Knowledge\Exceptions\JobPausedException;
use Starter\Knowledge\Exceptions\RetryLaterException;
use Starter\Knowledge\Models\RagJob;
use Throwable;

/**
 * Executes a durable job: atomic claim (also recovering jobs whose worker
 * died), handler by type, and a clear final state. Temporary provider
 * conditions release the queue message with a delay; content errors fail
 * the job with a sanitized message; cancelled work keeps its partial cost.
 */
class JobRunner
{
    public const MAX_ATTEMPTS = 5;

    public function __construct(
        protected SourceSync $sync,
        protected DocumentIndexer $indexer,
        protected Purger $purger,
        protected Reindexer $reindexer,
        protected Completion $completion,
    ) {}

    /**
     * @param  Closure(int): void  $release  releases the queue message with a delay
     */
    public function run(string $id, Closure $release): void
    {
        $job = $this->claim($id);

        if ($job === null) {
            return;
        }

        $context = new JobContext($job);

        try {
            $context->checkpoint(true);

            match ($job->type) {
                JobType::SyncSource => $this->sync->run($context),
                JobType::IndexDocument => $this->indexer->run($context),
                JobType::Reindex => $this->reindexer->run($context),
                JobType::PurgeDocument, JobType::PurgeSource, JobType::PurgeCollection, JobType::PurgeIndexVersion => $this->purger->run($context),
            };

            $job->refresh();

            // Parents waiting for children are finished by the last child...
            if ($job->status === JobStatus::Running && ! ($job->payload['waiting'] ?? false)) {
                $this->finish($context, JobStatus::Succeeded);
            }
        } catch (JobCancelledException $exception) {
            $this->finish($context, JobStatus::Cancelled, $exception->getMessage(), 'cancelled');
        } catch (JobPausedException $exception) {
            $context->flush();
            $job->forceFill(['status' => JobStatus::Paused, 'error' => $exception->getMessage(), 'error_type' => 'paused'])->save();
            $this->completion->childChanged($job);
        } catch (RetryLaterException $exception) {
            $this->retryLater($context, $release, $exception->getMessage(), $exception->delay, 'busy');
        } catch (FailoverableException $exception) {
            $this->retryLater($context, $release, $exception->getMessage(), 30 * $job->attempts, 'provider');
        } catch (BudgetExceededException|ModelNotUsableException $exception) {
            $this->finish($context, JobStatus::Failed, $exception->getMessage(), $exception instanceof BudgetExceededException ? 'budget' : 'model');
        } catch (Throwable $exception) {
            Log::warning('RAG job failed', ['job' => $job->id, 'type' => $job->type->value, 'exception' => $exception]);
            $this->finish($context, JobStatus::Failed, $this->sanitize($exception), 'error');
        }
    }

    /**
     * Take the job if it is waiting, or running with a stale heartbeat.
     */
    public function claim(string $id): ?RagJob
    {
        $stale = now()->subMinutes(max(1, (int) config('starter.rag.indexing.stale_after_minutes', 10)));

        // Times come from the application clock (the database now() is the
        // transaction start and may use another time zone)...
        $now = now();

        $claimed = DB::update(
            "UPDATE rag_jobs SET status = 'running', attempts = attempts + 1, started_at = COALESCE(started_at, ?), heartbeat_at = ?, updated_at = ?
             WHERE id = ? AND cancel_requested_at IS NULL AND (status IN ('queued', 'retrying') OR (status = 'running' AND heartbeat_at < ? AND NOT COALESCE((payload->>'waiting')::boolean, false)))",
            [$now, $now, $now, $id, $stale],
        );

        if ($claimed !== 1) {
            return null;
        }

        return RagJob::query()->with(['collection.connection', 'source'])->find($id);
    }

    public function finish(JobContext $context, JobStatus $status, ?string $error = null, ?string $type = null): void
    {
        $context->flush();
        $context->job->forceFill([
            'status' => $status,
            'error' => $error !== null ? Str::limit($error, 1000) : $context->job->error,
            'error_type' => $type,
            'finished_at' => now(),
            'reserved' => 0,
        ])->save();

        $this->completion->finished($context->job);
    }

    /**
     * @param  Closure(int): void  $release
     */
    protected function retryLater(JobContext $context, Closure $release, string $message, int $delay, string $type): void
    {
        if ($context->job->attempts >= self::MAX_ATTEMPTS) {
            $this->finish($context, JobStatus::Failed, $message, $type);

            return;
        }

        $context->flush();
        $context->job->forceFill(['status' => JobStatus::Retrying, 'error' => Str::limit($message, 1000), 'error_type' => $type])->save();
        $release(max(5, min($delay, 600)));
    }

    /**
     * Last resort when the worker gave up (timeout, too many exceptions).
     */
    public function markFailed(string $id, ?Throwable $exception): void
    {
        $job = RagJob::query()->find($id);

        if ($job === null || $job->isFinished()) {
            return;
        }

        $job->forceFill([
            'status' => JobStatus::Failed,
            'error' => $exception !== null ? $this->sanitize($exception) : __('The worker stopped the job.'),
            'error_type' => 'worker',
            'finished_at' => now(),
            'reserved' => 0,
        ])->save();

        $this->completion->finished($job);
    }

    public function sanitize(Throwable $exception): string
    {
        $message = preg_replace('/\(Connection: .*$/s', '', $exception->getMessage()) ?? '';
        $message = preg_replace('/(api[_-]?key|token|password|secret)\S*\s*[:=]\s*\S+/i', '$1=***', $message) ?? '';

        return Str::limit(trim($message), 500);
    }
}
