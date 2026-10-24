<?php

namespace Starter\Ai\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Starter\Ai\Agents\ToolKind;
use Starter\Ai\Enums\ProposalStatus;
use Starter\Identity\Identity;

/**
 * @property string $id
 * @property int $user_id
 * @property int|null $agent_version_id
 * @property string|null $ai_request_id
 * @property string|null $conversation_id
 * @property string $tool
 * @property string $tool_call_id
 * @property ToolKind $kind
 * @property string|null $resource_type
 * @property string|null $resource_id
 * @property string|null $resource_version
 * @property array<string, mixed> $arguments
 * @property string $arguments_hash
 * @property array{title: string, target: array{type: string|null, id: string|null, label: string|null}, changes: list<array{field: string, before: mixed, after: mixed}>, effects: list<string>, url: string|null} $preview
 * @property ProposalStatus $status
 * @property CarbonInterface $expires_at
 * @property CarbonInterface|null $decided_at
 * @property CarbonInterface|null $executed_at
 * @property array<string, mixed>|null $result
 * @property string|null $error
 * @property CarbonInterface $created_at
 * @property CarbonInterface $updated_at
 * @property-read AgentVersion|null $agentVersion
 */
class ActionProposal extends Model
{
    use HasUuids;

    protected $table = 'ai_action_proposals';

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
            'kind' => ToolKind::class,
            'status' => ProposalStatus::class,
            'arguments' => 'array',
            'preview' => 'array',
            'result' => 'array',
            'expires_at' => 'datetime',
            'decided_at' => 'datetime',
            'executed_at' => 'datetime',
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
     * @return BelongsTo<AgentVersion, $this>
     */
    public function agentVersion(): BelongsTo
    {
        return $this->belongsTo(AgentVersion::class, 'agent_version_id');
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    /**
     * @return array<string, mixed>
     */
    public function present(): array
    {
        return [
            'id' => $this->id,
            'tool' => $this->tool,
            'kind' => $this->kind->value,
            'status' => $this->status === ProposalStatus::Pending && $this->isExpired() ? ProposalStatus::Expired->value : $this->status->value,
            'preview' => $this->preview,
            'result' => $this->result,
            'error' => $this->error,
            'expires_at' => $this->expires_at->toIso8601String(),
            'created_at' => $this->created_at->toIso8601String(),
            'conversation_id' => $this->conversation_id,
        ];
    }
}
