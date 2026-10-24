<?php

namespace Starter\Ai\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Starter\Ai\Enums\CapabilityStatus;

/**
 * @property int $id
 * @property int $model_id
 * @property string $capability
 * @property CapabilityStatus $status
 * @property string|null $source
 * @property CarbonInterface|null $verified_at
 */
class ModelCapability extends Model
{
    protected $table = 'ai_model_capabilities';

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
            'status' => CapabilityStatus::class,
            'verified_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<AiModel, $this>
     */
    public function model(): BelongsTo
    {
        return $this->belongsTo(AiModel::class, 'model_id');
    }
}
