<?php

namespace App\Console\Commands;

use App\Actions\Knowledge\ControlJob;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;
use Starter\Knowledge\Enums\Capability;
use Starter\Knowledge\Exceptions\RagUnavailableException;
use Starter\Knowledge\Models\Collection;

/**
 * Start versioned reindexes from the console with the same Action as the
 * panel: the acting user (--as) needs rag.jobs.manage, the collection's
 * connection must support ingestion and every start is audited. The jobs
 * run on the queue worker; the active version keeps answering until the
 * new one is complete.
 */
class RagReindexCommand extends Command
{
    protected $signature = 'starter:rag-reindex
        {collections?* : Collection slugs or ids}
        {--all : Every active collection whose connection supports ingestion}
        {--as= : Email of the acting user (permissions and audit)}
        {--max-cost= : Confirmed budget in USD for each reindex}
        {--dry-run : Only list what would be reindexed}';

    protected $description = 'Start versioned reindexes of knowledge collections (queued jobs)';

    public function handle(ControlJob $control): int
    {
        $collections = $this->collections();

        if ($collections === []) {
            $this->components->error('No collection matches. Pass slugs/ids or --all.');

            return self::FAILURE;
        }

        if ($this->option('dry-run')) {
            $this->table(['Collection', 'Connection', 'Profile', 'Documents'], array_map(fn (Collection $collection) => [
                $collection->slug,
                $collection->connection->label,
                $collection->profile->name ?? '—',
                $collection->documents()->count(),
            ], $collections));

            return self::SUCCESS;
        }

        $actor = User::query()->where('email', (string) $this->option('as'))->first();

        if ($actor === null) {
            $this->components->error('Pass --as=<email> of an existing user: reindexes run with that user\'s permissions and are audited.');

            return self::FAILURE;
        }

        $failed = false;

        foreach ($collections as $collection) {
            try {
                $job = $control->reindex($actor, $collection, ['max_cost' => $this->option('max-cost')]);
                $this->components->twoColumnDetail($collection->slug, "<fg=green>queued</> job {$job->id}");
            } catch (AuthorizationException) {
                $this->components->error("{$actor->email} may not manage knowledge jobs (rag.jobs.manage).");

                return self::FAILURE;
            } catch (ValidationException|RagUnavailableException $exception) {
                $failed = true;
                $message = $exception instanceof ValidationException ? collect($exception->errors())->flatten()->implode(' ') : $exception->getMessage();
                $this->components->twoColumnDetail($collection->slug, "<fg=red>skipped</> {$message}");
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @return list<Collection>
     */
    protected function collections(): array
    {
        $query = Collection::query()->with(['connection', 'profile'])->orderBy('id');

        if ($this->option('all')) {
            return array_values($query->where('status', 'active')->get()
                ->filter(fn (Collection $collection) => $collection->connection->can(Capability::Ingest))
                ->all());
        }

        $keys = array_map('strval', (array) $this->argument('collections'));

        if ($keys === []) {
            return [];
        }

        return array_values($query->where(fn ($query) => $query
            ->whereIn('slug', $keys)
            ->orWhereIn('id', array_map('intval', array_filter($keys, 'ctype_digit'))))
            ->get()
            ->all());
    }
}
