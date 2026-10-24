<?php

namespace Starter\Knowledge\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $run_id
 * @property int $item_id
 * @property string|null $retrieval_run_id
 * @property list<int> $retrieved_document_ids
 * @property int|null $first_relevant_rank
 * @property float $recall
 * @property float $reciprocal_rank
 * @property int $latency_ms
 * @property string|null $cost
 * @property-read EvaluationItem $item
 */
class EvaluationResult extends Model
{
    protected $table = 'rag_evaluation_results';

    /**
     * @var list<string>
     */
    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'retrieved_document_ids' => 'array',
            'first_relevant_rank' => 'integer',
            'recall' => 'float',
            'reciprocal_rank' => 'float',
            'latency_ms' => 'integer',
            'cost' => 'decimal:10',
        ];
    }

    /**
     * @return BelongsTo<EvaluationItem, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(EvaluationItem::class, 'item_id');
    }
}
