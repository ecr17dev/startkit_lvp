<?php

namespace Starter\Ai\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $run_id
 * @property int|null $model_id
 * @property int $prompt_index
 * @property string $status
 * @property string|null $output
 * @property string|null $error
 * @property int|null $latency_ms
 * @property int|null $ttft_ms
 * @property int $input_tokens
 * @property int $output_tokens
 * @property string|null $estimated_cost
 * @property string|null $cost
 * @property string|null $cost_status
 * @property string|null $ai_request_id
 * @property CarbonInterface|null $started_at
 * @property CarbonInterface|null $finished_at
 * @property-read AiModel|null $model
 * @property-read LabRun $run
 */
class LabResult extends Model
{
    public const PENDING = 'pending';

    public const RUNNING = 'running';

    public const SUCCEEDED = 'succeeded';

    public const FAILED = 'failed';

    public const SKIPPED = 'skipped';

    protected $table = 'ai_lab_results';

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
            'prompt_index' => 'integer',
            'latency_ms' => 'integer',
            'ttft_ms' => 'integer',
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
            'estimated_cost' => 'decimal:10',
            'cost' => 'decimal:10',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<AiModel, $this>
     */
    public function model(): BelongsTo
    {
        return $this->belongsTo(AiModel::class, 'model_id');
    }

    /**
     * @return BelongsTo<LabRun, $this>
     */
    public function run(): BelongsTo
    {
        return $this->belongsTo(LabRun::class, 'run_id');
    }
}
