<?php

namespace App\Actions\Knowledge;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Starter\Knowledge\Enums\Capability;
use Starter\Knowledge\Enums\IndexVersionStatus;
use Starter\Knowledge\Enums\JobStatus;
use Starter\Knowledge\Enums\JobType;
use Starter\Knowledge\Enums\SourceStatus;
use Starter\Knowledge\Knowledge;
use Starter\Knowledge\Models\Collection;
use Starter\Knowledge\Models\Document;
use Starter\Knowledge\Models\EmbeddingProfile;
use Starter\Knowledge\Models\IndexVersion;
use Starter\Knowledge\Models\RagJob;
use Starter\Knowledge\Models\Source;
use Starter\Knowledge\Pipeline\Deletions;
use Starter\Knowledge\Pipeline\RagJobs;
use Starter\Knowledge\Store\Provisioner;
use Starter\Knowledge\Store\VectorStore;

/**
 * Deletions: removed from queries at once, purged by traceable jobs.
 */
class DeleteKnowledge
{
    public function __construct(
        protected Deletions $deletions,
        protected RagJobs $jobs,
        protected VectorStore $store,
        protected Provisioner $provisioner,
        protected Knowledge $knowledge,
    ) {}

    public function document(User $actor, Document $document): RagJob
    {
        Gate::forUser($actor)->authorize('rag.documents.delete');
        $this->knowledge->ensureFor($document->collection, Capability::Delete);

        $job = $this->deletions->delete($document, 'manual', null, $actor->id);

        audit('rag.documents.deleted', $document, ['title' => $document->title], $actor);

        return $job;
    }

    public function source(User $actor, Source $source): RagJob
    {
        Gate::forUser($actor)->authorize('rag.sources.manage');
        $this->knowledge->ensureFor($source->collection, Capability::Delete);

        $this->cancelOpenJobs($source->jobs()->whereNull('parent_id')->whereIn('status', JobStatus::open())->get());

        $source->forceFill(['status' => SourceStatus::Deleting, 'schedule' => null, 'next_sync_at' => null])->save();
        $source->documents()->alive()->update(['deleted_at' => now(), 'status' => 'deleted']);
        $this->store->deleteSource($source->collection->connection, $source->id);

        $job = $this->jobs->create(JobType::PurgeSource, ['collection_id' => $source->collection_id, 'created_by' => $actor->id, 'payload' => ['source_id' => $source->id, 'source_name' => $source->name]]);

        audit('rag.sources.deleted', $source, ['name' => $source->name], $actor);

        return $job;
    }

    /**
     * Deleting a remote collection only removes its registration here
     * (nothing is deleted on the retrieval API, and there is no job).
     */
    public function collection(User $actor, Collection $collection, string $confirmation): ?RagJob
    {
        Gate::forUser($actor)->authorize('rag.collections.manage');

        if ($confirmation !== $collection->slug) {
            throw ValidationException::withMessages(['confirmation' => __('Type the collection identifier to confirm.')]);
        }

        if ($collection->isRemote()) {
            $collection->delete();
            audit('rag.collections.deleted', null, ['name' => $collection->name, 'remote' => $collection->external_id], $actor);

            return null;
        }

        $this->knowledge->ensureFor($collection, Capability::Delete);

        $this->cancelOpenJobs($collection->jobs()->whereNull('parent_id')->whereIn('status', JobStatus::open())->get());
        $collection->forceFill(['status' => Collection::DELETING])->save();

        $job = $this->jobs->create(JobType::PurgeCollection, ['collection_id' => $collection->id, 'created_by' => $actor->id, 'payload' => ['collection_id' => $collection->id, 'collection_name' => $collection->name]]);

        audit('rag.collections.deleted', $collection, ['name' => $collection->name], $actor);

        return $job;
    }

    public function profile(User $actor, EmbeddingProfile $profile): void
    {
        Gate::forUser($actor)->authorize('rag.collections.manage');

        $inUse = Collection::query()->where('embedding_profile_id', $profile->id)->exists()
            || IndexVersion::query()->where('embedding_profile_id', $profile->id)->whereNull('purged_at')->whereIn('status', [IndexVersionStatus::Active->value, IndexVersionStatus::Building->value, IndexVersionStatus::Superseded->value])->exists();

        if ($inUse) {
            throw ValidationException::withMessages(['profile' => __('The profile is used by a collection or an index version.')]);
        }

        DB::transaction(function () use ($profile) {
            IndexVersion::query()->where('embedding_profile_id', $profile->id)->delete();
            $profile->delete();
        });

        $this->provisioner->dropProfile($profile);

        audit('rag.profiles.deleted', $profile, ['name' => $profile->name], $actor);
    }

    /**
     * @param  iterable<RagJob>  $jobs
     */
    protected function cancelOpenJobs(iterable $jobs): void
    {
        foreach ($jobs as $job) {
            $this->jobs->cancel($job);
        }
    }
}
