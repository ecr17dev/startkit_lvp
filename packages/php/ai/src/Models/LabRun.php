<?php

namespace Starter\Ai\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $name
 * @property list<string> $prompts
 * @property string|null $system_prompt
 * @property list<int> $model_ids
 * @property int|null $max_tokens
 * @property string $max_cost
 * @property int $max_concurrency
 * @property string $spent
 * @property string $reserved
 * @property string $status
 * @property int|null $created_by
 * @property CarbonInterface|null $started_at
 * @property CarbonInterface|null $finished_at
 * @property CarbonInterface $created_at
 * @property CarbonInterface $updated_at
 */
class LabRun extends Model
{
    public const QUEUED = 'queued';

    public const RUNNING = 'running';

    public const COMPLETED = 'completed';

    public const CANCELLED = 'cancelled';

    protected $table = 'ai_lab_runs';

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
            'prompts' => 'array',
            'model_ids' => 'array',
            'max_tokens' => 'integer',
            'max_cost' => 'decimal:10',
            'max_concurrency' => 'integer',
            'spent' => 'decimal:10',
            'reserved' => 'decimal:10',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<LabResult, $this>
     */
    public function results(): HasMany
    {
        return $this->hasMany(LabResult::class, 'run_id');
    }

    public function isActive(): bool
    {
        return in_array($this->status, [self::QUEUED, self::RUNNING], true);
    }
}
