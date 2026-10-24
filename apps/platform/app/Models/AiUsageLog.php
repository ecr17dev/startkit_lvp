<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int|null $user_id
 * @property int|null $ai_prompt_id
 * @property string $invocation_id
 * @property string|null $conversation_id
 * @property string $agent
 * @property string $source
 * @property string $provider
 * @property string $model
 * @property int $input_tokens
 * @property int $output_tokens
 * @property int $cache_read_tokens
 * @property int $cache_write_tokens
 * @property int $reasoning_tokens
 * @property string $cost
 * @property bool $priced
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User|null $user
 * @property-read AiPrompt|null $prompt
 */
#[Fillable([
    'user_id', 'ai_prompt_id', 'invocation_id', 'conversation_id', 'agent', 'source', 'provider', 'model',
    'input_tokens', 'output_tokens', 'cache_read_tokens', 'cache_write_tokens', 'reasoning_tokens', 'cost', 'priced',
])]
class AiUsageLog extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
            'cache_read_tokens' => 'integer',
            'cache_write_tokens' => 'integer',
            'reasoning_tokens' => 'integer',
            'cost' => 'decimal:8',
            'priced' => 'boolean',
        ];
    }

    /**
     * Get the user that triggered the call.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the system prompt used by the call.
     *
     * @return BelongsTo<AiPrompt, $this>
     */
    public function prompt(): BelongsTo
    {
        return $this->belongsTo(AiPrompt::class, 'ai_prompt_id');
    }
}
