<?php

namespace Starter\Administration\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $action
 * @property string $channel
 * @property int|null $actor_id
 * @property string|null $actor_label
 * @property string|null $subject_type
 * @property string|null $subject_id
 * @property array<string, mixed>|null $properties
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property string|null $request_id
 * @property CarbonInterface $created_at
 */
class AuditEvent extends Model
{
    use MassPrunable;

    public const UPDATED_AT = null;

    /**
     * The attributes that aren't mass assignable.
     *
     * @var list<string>
     */
    protected $guarded = ['id'];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'properties' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /**
     * Get the user who performed the action.
     *
     * @return BelongsTo<Model, $this>
     */
    public function actor(): BelongsTo
    {
        /** @var class-string<Model> $model */
        $model = config('auth.providers.users.model');

        return $this->belongsTo($model, 'actor_id')->withDefault();
    }

    /**
     * Events older than the retention window are pruned by `model:prune`.
     *
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        $days = (int) config('starter.audit.retention_days', 365);

        return static::query()->where('created_at', '<', now()->subDays(max(1, $days)));
    }
}
