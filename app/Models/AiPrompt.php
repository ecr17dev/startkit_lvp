<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property string|null $description
 * @property string $content
 * @property int|null $ai_provider_id
 * @property string|null $model
 * @property string|null $temperature
 * @property int|null $max_tokens
 * @property bool $is_default
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read AiProvider|null $provider
 */
#[Fillable(['name', 'slug', 'description', 'content', 'ai_provider_id', 'model', 'temperature', 'max_tokens', 'is_default', 'is_active'])]
class AiPrompt extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'temperature' => 'decimal:2',
            'max_tokens' => 'integer',
            'is_default' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Get the provider pinned to the prompt, if any.
     *
     * @return BelongsTo<AiProvider, $this>
     */
    public function provider(): BelongsTo
    {
        return $this->belongsTo(AiProvider::class, 'ai_provider_id');
    }

    /**
     * Scope the query to active prompts.
     *
     * @param  Builder<AiPrompt>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * Get the default prompt, falling back to the first active one.
     */
    public static function default(): ?self
    {
        return static::query()->active()->orderByDesc('is_default')->orderBy('id')->first();
    }

    /**
     * Render the prompt content replacing the {{ variables }}.
     *
     * @param  array<string, string|int|float|null>  $variables
     */
    public function render(array $variables = []): string
    {
        return (string) preg_replace_callback(
            '/\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/',
            fn (array $match) => array_key_exists($match[1], $variables) ? (string) $variables[$match[1]] : $match[0],
            $this->content,
        );
    }
}
