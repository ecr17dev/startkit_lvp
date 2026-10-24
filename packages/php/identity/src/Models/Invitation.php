<?php

namespace Starter\Identity\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use Starter\Identity\Identity;

/**
 * @property int $id
 * @property string $email
 * @property string $role
 * @property string $token_hash
 * @property int|null $invited_by
 * @property int|null $accepted_user_id
 * @property CarbonInterface $expires_at
 * @property CarbonInterface|null $accepted_at
 * @property CarbonInterface|null $revoked_at
 * @property CarbonInterface|null $last_sent_at
 * @property int $sends
 * @property CarbonInterface $created_at
 * @property CarbonInterface $updated_at
 * @property-read Model|null $inviter
 * @property-read Model|null $acceptedUser
 */
class Invitation extends Model
{
    /**
     * The attributes that aren't mass assignable.
     *
     * @var list<string>
     */
    protected $guarded = ['id'];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = ['token_hash'];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
            'revoked_at' => 'datetime',
            'last_sent_at' => 'datetime',
            'sends' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Model, $this>
     */
    public function inviter(): BelongsTo
    {
        return $this->belongsTo(Identity::userModel(), 'invited_by');
    }

    /**
     * @return BelongsTo<Model, $this>
     */
    public function acceptedUser(): BelongsTo
    {
        return $this->belongsTo(Identity::userModel(), 'accepted_user_id');
    }

    /**
     * Generate a new link token, storing only its hash.
     */
    public function refreshToken(): string
    {
        $token = Str::random(48);
        $this->token_hash = self::hashToken($token);

        return $token;
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * Find the pending invitation for a link token.
     */
    public static function findPendingByToken(string $token): ?self
    {
        return self::query()->pending()->where('token_hash', self::hashToken($token))->first();
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopePending(Builder $query): void
    {
        $query->whereNull('accepted_at')->whereNull('revoked_at')->where('expires_at', '>', now());
    }

    public function isPending(): bool
    {
        return $this->accepted_at === null && $this->revoked_at === null && $this->expires_at->isFuture();
    }

    /**
     * Get the status shown in the panel.
     */
    public function status(): string
    {
        return match (true) {
            $this->accepted_at !== null => 'accepted',
            $this->revoked_at !== null => 'revoked',
            $this->expires_at->isPast() => 'expired',
            default => 'pending',
        };
    }
}
