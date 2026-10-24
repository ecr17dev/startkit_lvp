<?php

namespace Starter\Identity\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Starter\Identity\Identity;
use Starter\Identity\Otp\OtpPurpose;

/**
 * @property string $id
 * @property OtpPurpose $purpose
 * @property string $email
 * @property int|null $user_id
 * @property int|null $invitation_id
 * @property string $verifier
 * @property bool $deliverable
 * @property int $attempts
 * @property int $max_attempts
 * @property array<string, mixed>|null $payload
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property CarbonInterface $expires_at
 * @property CarbonInterface $resend_available_at
 * @property CarbonInterface|null $consumed_at
 * @property CarbonInterface|null $invalidated_at
 * @property CarbonInterface $created_at
 * @property CarbonInterface $updated_at
 * @property-read Model|null $user
 * @property-read Invitation|null $invitation
 */
class LoginChallenge extends Model
{
    use HasUuids, MassPrunable;

    /**
     * The attributes that aren't mass assignable.
     *
     * @var list<string>
     */
    protected $guarded = [];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = ['verifier'];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'purpose' => OtpPurpose::class,
            'deliverable' => 'boolean',
            'attempts' => 'integer',
            'max_attempts' => 'integer',
            'payload' => 'array',
            'expires_at' => 'datetime',
            'resend_available_at' => 'datetime',
            'consumed_at' => 'datetime',
            'invalidated_at' => 'datetime',
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
     * @return BelongsTo<Invitation, $this>
     */
    public function invitation(): BelongsTo
    {
        return $this->belongsTo(Invitation::class);
    }

    /**
     * Scope the challenges that can still be verified.
     *
     * @param  Builder<self>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('consumed_at')
            ->whereNull('invalidated_at')
            ->where('expires_at', '>', now())
            ->whereColumn('attempts', '<', 'max_attempts');
    }

    public function isActive(): bool
    {
        return $this->consumed_at === null
            && $this->invalidated_at === null
            && $this->expires_at->isFuture()
            && $this->attempts < $this->max_attempts;
    }

    /**
     * Seconds until a new code can be sent.
     */
    public function resendAvailableIn(): int
    {
        return max(0, (int) ceil(now()->diffInSeconds($this->resend_available_at, false)));
    }

    /**
     * Get a value of the payload.
     */
    public function payload(string $key, mixed $default = null): mixed
    {
        return data_get($this->payload ?? [], $key, $default);
    }

    /**
     * Challenges are removed one day after they stop being usable.
     *
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        return static::query()->where('expires_at', '<', now()->subDay());
    }
}
