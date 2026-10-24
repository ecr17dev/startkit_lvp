<?php

namespace Starter\Ai\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Starter\Ai\Enums\CostStatus;
use Starter\Ai\Enums\Feature;
use Starter\Ai\Enums\RequestStatus;
use Starter\Identity\Identity;

/**
 * A logical AI execution of the usage ledger.
 *
 * @property string $id
 * @property string|null $idempotency_key
 * @property string|null $request_id
 * @property int|null $user_id
 * @property int|null $organization_id
 * @property string|null $agent
 * @property int|null $agent_version_id
 * @property string|null $conversation_id
 * @property Feature $feature
 * @property int|null $connection_id
 * @property int|null $model_id
 * @property string|null $provider
 * @property string|null $model
 * @property RequestStatus $status
 * @property int $input_tokens
 * @property int $output_tokens
 * @property int $cache_read_tokens
 * @property int $cache_write_tokens
 * @property int $reasoning_tokens
 * @property array<string, int|float>|null $units
 * @property string|null $estimated_cost
 * @property string|null $cost
 * @property string|null $reconciled_cost
 * @property CostStatus $cost_status
 * @property string $currency
 * @property int $attempts_count
 * @property int|null $latency_ms
 * @property int|null $time_to_first_token_ms
 * @property string|null $error_type
 * @property string|null $error_message
 * @property array<string, mixed>|null $metadata
 * @property CarbonInterface|null $started_at
 * @property CarbonInterface|null $finished_at
 * @property CarbonInterface $created_at
 * @property CarbonInterface $updated_at
 * @property-read Connection|null $connection
 * @property-read AiModel|null $catalogModel
 */
class AiRequest extends Model
{
    use HasUuids;

    protected $table = 'ai_requests';

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
            'feature' => Feature::class,
            'status' => RequestStatus::class,
            'cost_status' => CostStatus::class,
            'units' => 'array',
            'metadata' => 'array',
            'estimated_cost' => 'decimal:10',
            'cost' => 'decimal:10',
            'reconciled_cost' => 'decimal:10',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Model, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(Identity::userModel());
    }

    /**
     * @return BelongsTo<Connection, $this>
     */
    public function connection(): BelongsTo
    {
        return $this->belongsTo(Connection::class, 'connection_id');
    }

    /**
     * @return BelongsTo<AiModel, $this>
     */
    public function catalogModel(): BelongsTo
    {
        return $this->belongsTo(AiModel::class, 'model_id');
    }

    /**
     * @return HasMany<AiAttempt, $this>
     */
    public function attempts(): HasMany
    {
        return $this->hasMany(AiAttempt::class, 'ai_request_id')->orderBy('number');
    }

    /**
     * @return HasMany<CostSnapshot, $this>
     */
    public function costSnapshots(): HasMany
    {
        return $this->hasMany(CostSnapshot::class, 'ai_request_id');
    }

    /**
     * @return HasMany<BudgetReservation, $this>
     */
    public function reservations(): HasMany
    {
        return $this->hasMany(BudgetReservation::class, 'ai_request_id');
    }

    /**
     * The best known amount: reconciled, then calculated. Null when unknown.
     */
    public function effectiveCost(): ?string
    {
        return $this->reconciled_cost ?? $this->cost;
    }
}
