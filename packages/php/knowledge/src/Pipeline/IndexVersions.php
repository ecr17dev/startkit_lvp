<?php

namespace Starter\Knowledge\Pipeline;

use Illuminate\Support\Facades\DB;
use Starter\Knowledge\Enums\IndexVersionStatus;
use Starter\Knowledge\Models\Collection;
use Starter\Knowledge\Models\IndexVersion;
use Starter\Knowledge\Store\VectorStore;

/**
 * Index versions of a collection. The first one is published empty and
 * filled incrementally; later ones are built by a reindex and published
 * only when complete.
 */
class IndexVersions
{
    public function __construct(protected VectorStore $store) {}

    public function ensureActive(Collection $collection): IndexVersion
    {
        if ($collection->active_index_version_id !== null && ($active = $collection->activeVersion) !== null) {
            return $active;
        }

        return DB::transaction(function () use ($collection) {
            $locked = Collection::query()->lockForUpdate()->findOrFail($collection->id);

            if ($locked->active_index_version_id !== null) {
                $collection->setRelation('activeVersion', $locked->activeVersion);
                $collection->active_index_version_id = $locked->active_index_version_id;

                return $locked->activeVersion;
            }

            $version = $locked->indexVersions()->create([
                'embedding_profile_id' => $locked->embedding_profile_id,
                'number' => (int) $locked->indexVersions()->max('number') + 1,
                'status' => IndexVersionStatus::Active,
                'reason' => 'initial',
                'chunking' => $locked->chunking,
                'published_at' => now(),
            ]);

            $locked->forceFill(['active_index_version_id' => $version->id])->save();
            $collection->forceFill(['active_index_version_id' => $version->id])->setRelation('activeVersion', $version);

            return $version;
        });
    }

    /**
     * Versions that receive new document versions: active and building.
     *
     * @return list<IndexVersion>
     */
    public function targets(Collection $collection): array
    {
        return array_values($collection->indexVersions()
            ->with('profile.connection')
            ->whereIn('status', [IndexVersionStatus::Active->value, IndexVersionStatus::Building->value])
            ->orderBy('number')
            ->get()
            ->all());
    }

    public function refreshStats(IndexVersion $version): void
    {
        $stats = $this->store->stats($version->collection->connection, $version->id);

        $version->forceFill($stats)->save();
    }

    /**
     * Add the cost of an embedding call to the version.
     */
    public function addCost(IndexVersion $version, ?string $amount): void
    {
        if ($amount === null) {
            $version->newQuery()->whereKey($version->id)->update(['cost_known' => false]);

            return;
        }

        DB::update('UPDATE rag_index_versions SET cost = cost + CAST(? AS numeric), updated_at = now() WHERE id = ?', [$amount, $version->id]);
    }
}
