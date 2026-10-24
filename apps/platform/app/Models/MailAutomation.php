<?php

namespace App\Models;

use App\Mail\Automation\MailAutomations;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $name
 * @property string $trigger user_created|inactivity|scheduled|event
 * @property array<string, mixed> $settings
 * @property string $template_key
 * @property bool $is_active
 * @property Carbon|null $last_run_at
 * @property int|null $created_by
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class MailAutomation extends Model
{
    public const TRIGGERS = ['user_created', 'inactivity', 'scheduled', 'event'];

    protected $fillable = ['name', 'trigger', 'settings', 'template_key', 'is_active', 'last_run_at', 'created_by'];

    protected static function booted(): void
    {
        // The engine keeps the active automations of the request in memory...
        static::saved(fn () => app(MailAutomations::class)->flush());
        static::deleted(fn () => app(MailAutomations::class)->flush());
    }

    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'is_active' => 'boolean',
            'last_run_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<MailDelivery, $this>
     */
    public function deliveries(): HasMany
    {
        return $this->hasMany(MailDelivery::class, 'automation_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function setting(string $key, mixed $default = null): mixed
    {
        return $this->settings[$key] ?? $default;
    }
}
