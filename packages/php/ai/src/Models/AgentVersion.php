<?php

namespace Starter\Ai\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Starter\Ai\Agents\AgentConfig;
use Starter\Ai\Enums\AgentVersionStatus;

/**
 * @property int $id
 * @property int $agent_id
 * @property int $number
 * @property AgentVersionStatus $status
 * @property array<string, mixed> $config
 * @property string|null $notes
 * @property int|null $created_by
 * @property int|null $published_by
 * @property CarbonInterface|null $published_at
 * @property CarbonInterface $created_at
 * @property CarbonInterface $updated_at
 * @property-read Agent $agent
 */
class AgentVersion extends Model
{
    protected $table = 'ai_agent_versions';

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
            'status' => AgentVersionStatus::class,
            'config' => 'array',
            'published_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Agent, $this>
     */
    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class, 'agent_id');
    }

    /**
     * The settings of the version, completed with the current defaults.
     */
    public function settings(): AgentConfig
    {
        return AgentConfig::from($this->config);
    }

    public function isDraft(): bool
    {
        return $this->status === AgentVersionStatus::Draft;
    }
}
