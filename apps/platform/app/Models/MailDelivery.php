<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One email of an automation: pending until the queue sends it.
 *
 * @property int $id
 * @property int|null $automation_id
 * @property string $template_key
 * @property int|null $user_id
 * @property string $email
 * @property string|null $subject
 * @property array<string, scalar|null>|null $variables
 * @property string $status pending|sent|failed|skipped
 * @property string|null $error
 * @property string|null $delivery_key
 * @property Carbon|null $send_at
 * @property Carbon|null $sent_at
 * @property Carbon $created_at
 */
class MailDelivery extends Model
{
    protected $fillable = ['automation_id', 'template_key', 'user_id', 'email', 'subject', 'variables', 'status', 'error', 'delivery_key', 'send_at', 'sent_at'];

    protected function casts(): array
    {
        return [
            'variables' => 'array',
            'send_at' => 'datetime',
            'sent_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<MailAutomation, $this>
     */
    public function automation(): BelongsTo
    {
        return $this->belongsTo(MailAutomation::class, 'automation_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
