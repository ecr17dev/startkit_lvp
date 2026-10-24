<?php

namespace Starter\Ai\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Starter\Ai\Enums\AgentVersionStatus;

/**
 * @property int $id
 * @property string $slug
 * @property string $name
 * @property string|null $description
 * @property bool $is_active
 * @property bool $is_default
 * @property int|null $published_version_id
 * @property int|null $created_by
 * @property CarbonInterface $created_at
 * @property CarbonInterface $updated_at
 * @property-read AgentVersion|null $publishedVersion
 * @property-read AgentVersion|null $draft
 */
class Agent extends Model
{
    protected $table = 'ai_agents';

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
            'is_active' => 'boolean',
            'is_default' => 'boolean',
        ];
    }

    /**
     * @return HasMany<AgentVersion, $this>
     */
    public function versions(): HasMany
    {
        return $this->hasMany(AgentVersion::class, 'agent_id');
    }

    /**
     * @return BelongsTo<AgentVersion, $this>
     */
    public function publishedVersion(): BelongsTo
    {
        return $this->belongsTo(AgentVersion::class, 'published_version_id');
    }

    /**
     * @return HasOne<AgentVersion, $this>
     */
    public function draft(): HasOne
    {
        return $this->hasOne(AgentVersion::class, 'agent_id')->where('status', AgentVersionStatus::Draft->value);
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * Agents with a published version that can serve conversations.
     *
     * @param  Builder<self>  $query
     */
    public function scopePublished(Builder $query): void
    {
        $query->where('is_active', true)->whereNotNull('published_version_id');
    }
}
