<?php

namespace Starter\Knowledge\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property int $set_id
 * @property string|null $label
 * @property array{method?: string|null, top_k?: int|null, min_score?: float|null, max_context_tokens?: int|null} $settings
 * @property string $status
 * @property array<string, mixed>|null $metrics
 * @property string|null $error
 * @property int|null $created_by
 * @property CarbonInterface|null $started_at
 * @property CarbonInterface|null $finished_at
 * @property CarbonInterface $created_at
 * @property CarbonInterface $updated_at
 * @property-read EvaluationSet $set
 */
class EvaluationRun extends Model
{
    use HasUuids;

    public const QUEUED = 'queued';

    public const RUNNING = 'running';

    public const COMPLETED = 'completed';

    public const FAILED = 'failed';

    protected $table = 'rag_evaluation_runs';

    /**
     * @var list<string>
     */
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'metrics' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<EvaluationSet, $this>
     */
    public function set(): BelongsTo
    {
        return $this->belongsTo(EvaluationSet::class, 'set_id');
    }

    /**
     * @return HasMany<EvaluationResult, $this>
     */
    public function results(): HasMany
    {
        return $this->hasMany(EvaluationResult::class, 'run_id');
    }
}
