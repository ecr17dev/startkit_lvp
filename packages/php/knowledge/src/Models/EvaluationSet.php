<?php

namespace Starter\Knowledge\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Fixed questions with the documents that should be retrieved, used to
 * compare retrieval settings over time.
 *
 * @property int $id
 * @property string $name
 * @property string|null $description
 * @property list<int> $collection_ids
 * @property int|null $created_by
 * @property CarbonInterface $created_at
 * @property CarbonInterface $updated_at
 */
class EvaluationSet extends Model
{
    protected $table = 'rag_evaluation_sets';

    /**
     * @var list<string>
     */
    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['collection_ids' => 'array'];
    }

    /**
     * @return HasMany<EvaluationItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(EvaluationItem::class, 'set_id');
    }

    /**
     * @return HasMany<EvaluationRun, $this>
     */
    public function runs(): HasMany
    {
        return $this->hasMany(EvaluationRun::class, 'set_id');
    }
}
