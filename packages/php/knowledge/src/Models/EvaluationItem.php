<?php

namespace Starter\Knowledge\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $set_id
 * @property string $question
 * @property list<int> $expected_document_ids
 * @property string|null $expected_answer
 * @property CarbonInterface $created_at
 * @property CarbonInterface $updated_at
 */
class EvaluationItem extends Model
{
    protected $table = 'rag_evaluation_items';

    /**
     * @var list<string>
     */
    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['expected_document_ids' => 'array'];
    }

    /**
     * @return BelongsTo<EvaluationSet, $this>
     */
    public function set(): BelongsTo
    {
        return $this->belongsTo(EvaluationSet::class, 'set_id');
    }
}
