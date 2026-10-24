<?php

namespace Starter\Knowledge\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $run_id
 * @property int $rank
 * @property int $chunk_id
 * @property int|null $document_id
 * @property int|null $collection_id
 * @property float $score
 * @property string $score_type
 * @property float|null $vector_score
 * @property float|null $text_score
 * @property int $token_count
 * @property bool $cited
 * @property string|null $title
 * @property array<string, mixed>|null $location
 */
class RetrievalHit extends Model
{
    public $timestamps = false;

    protected $table = 'rag_retrieval_hits';

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
            'rank' => 'integer',
            'score' => 'float',
            'vector_score' => 'float',
            'text_score' => 'float',
            'token_count' => 'integer',
            'cited' => 'boolean',
            'location' => 'array',
        ];
    }

    /**
     * @return BelongsTo<RetrievalRun, $this>
     */
    public function run(): BelongsTo
    {
        return $this->belongsTo(RetrievalRun::class, 'run_id');
    }

    /**
     * @return BelongsTo<Document, $this>
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }
}
