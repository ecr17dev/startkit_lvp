<?php

namespace Starter\Ai\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Starter\Ai\Enums\CostStatus;

/**
 * A real call to a provider (a request may retry or fail over).
 *
 * @property int $id
 * @property string $ai_request_id
 * @property int $number
 * @property string|null $invocation_id
 * @property int|null $connection_id
 * @property int|null $model_id
 * @property string|null $provider
 * @property string|null $model
 * @property string $status
 * @property bool $billable
 * @property int $input_tokens
 * @property int $output_tokens
 * @property int $cache_read_tokens
 * @property int $cache_write_tokens
 * @property int $reasoning_tokens
 * @property array<string, int|float>|null $units
 * @property string|null $cost
 * @property CostStatus $cost_status
 * @property int|null $latency_ms
 * @property string|null $error_type
 * @property string|null $error_message
 * @property CarbonInterface|null $started_at
 * @property CarbonInterface|null $finished_at
 * @property-read AiRequest $request
 */
class AiAttempt extends Model
{
    protected $table = 'ai_attempts';

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
            'billable' => 'boolean',
            'units' => 'array',
            'cost' => 'decimal:10',
            'cost_status' => CostStatus::class,
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<AiRequest, $this>
     */
    public function request(): BelongsTo
    {
        return $this->belongsTo(AiRequest::class, 'ai_request_id');
    }
}
