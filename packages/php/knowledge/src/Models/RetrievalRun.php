<?php

namespace Starter\Knowledge\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property int|null $user_id
 * @property int|null $agent_version_id
 * @property string|null $conversation_id
 * @property string|null $message_id
 * @property string|null $ai_request_id
 * @property string|null $chat_request_id
 * @property string $surface
 * @property string $query
 * @property string $method
 * @property array<string, mixed> $settings
 * @property list<int> $collection_ids
 * @property int $hits_count
 * @property int $context_tokens
 * @property int $latency_ms
 * @property int|null $embedding_ms
 * @property int $search_ms
 * @property string|null $cost
 * @property bool $cost_known
 * @property bool $used
 * @property string|null $outcome
 * @property int $citations_count
 * @property int $invalid_citations
 * @property int|null $feedback
 * @property string|null $feedback_note
 * @property CarbonInterface|null $feedback_at
 * @property CarbonInterface|null $created_at
 */
class RetrievalRun extends Model
{
    use HasUuids, MassPrunable;

    public const UPDATED_AT = null;

    public const ANSWERED = 'answered';

    public const ABSTAINED = 'abstained';

    public const GENERAL = 'general';

    public const UNSUPPORTED = 'unsupported';

    protected $table = 'rag_retrieval_runs';

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
            'collection_ids' => 'array',
            'hits_count' => 'integer',
            'context_tokens' => 'integer',
            'latency_ms' => 'integer',
            'embedding_ms' => 'integer',
            'search_ms' => 'integer',
            'cost' => 'decimal:10',
            'cost_known' => 'boolean',
            'used' => 'boolean',
            'citations_count' => 'integer',
            'invalid_citations' => 'integer',
            'feedback' => 'integer',
            'feedback_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<RetrievalHit, $this>
     */
    public function hits(): HasMany
    {
        return $this->hasMany(RetrievalHit::class, 'run_id')->orderBy('rank');
    }

    /**
     * Traces older than the retention are removed by `model:prune`.
     *
     * @return Builder<self>
     */
    public function prunable(): Builder
    {
        return self::query()->where('created_at', '<', now()->subDays(max(1, (int) config('starter.rag.retrieval_retention_days', 90))));
    }
}
