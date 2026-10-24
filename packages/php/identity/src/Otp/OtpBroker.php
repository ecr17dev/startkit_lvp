<?php

namespace Starter\Identity\Otp;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Starter\Identity\Models\LoginChallenge;
use Starter\Identity\Notifications\OneTimeCodeNotification;

/**
 * Issues and verifies one-time codes.
 *
 * - The code is never stored: the verifier is an HMAC of the challenge id,
 *   the purpose and the code with a server secret.
 * - Each challenge allows a limited number of attempts, counted atomically,
 *   and is consumed atomically, so a code works only once.
 * - Issuing a new code invalidates the previous ones for the same email and
 *   purpose; a new code cannot be sent before the resend cooldown.
 * - Callers decide whether a challenge is deliverable. Non deliverable
 *   challenges behave exactly like the others but nothing is sent and
 *   they never verify, so responses do not reveal which accounts exist.
 */
class OtpBroker
{
    /**
     * Issue a challenge, reusing the active one while the resend cooldown runs.
     *
     * @param  array{user_id?: int|null, invitation_id?: int|null, deliverable?: bool, payload?: array<string, mixed>|null, ip_address?: string|null, user_agent?: string|null}  $attributes
     */
    public function issue(OtpPurpose $purpose, string $email, array $attributes = []): LoginChallenge
    {
        $email = self::normalizeEmail($email);

        $recent = LoginChallenge::query()
            ->active()
            ->where('email', $email)
            ->where('purpose', $purpose->value)
            ->where('invitation_id', $attributes['invitation_id'] ?? null)
            ->where('resend_available_at', '>', now())
            ->latest()
            ->first();

        return $recent ?? $this->createChallenge($purpose, $email, $attributes);
    }

    /**
     * Send a new code for the challenge once the cooldown has passed.
     *
     * Returns the new challenge, or the same one when it cannot be resent yet.
     */
    public function resend(LoginChallenge $challenge): LoginChallenge
    {
        if ($challenge->consumed_at !== null || $challenge->resendAvailableIn() > 0) {
            return $challenge;
        }

        return $this->createChallenge($challenge->purpose, $challenge->email, [
            'user_id' => $challenge->user_id,
            'invitation_id' => $challenge->invitation_id,
            'deliverable' => $challenge->deliverable,
            'payload' => $challenge->payload,
            'ip_address' => $challenge->ip_address,
            'user_agent' => $challenge->user_agent,
        ]);
    }

    /**
     * Verify a code, counting the attempt and consuming the challenge on success.
     */
    public function verify(string $challengeId, string $code, ?OtpPurpose $purpose = null): OtpVerification
    {
        if (! Str::isUuid($challengeId)) {
            return new OtpVerification(OtpStatus::Invalid);
        }

        // Count the attempt first, atomically: concurrent guesses cannot
        // exceed the maximum number of attempts...
        $counted = LoginChallenge::query()
            ->whereKey($challengeId)
            ->active()
            ->when($purpose, fn ($query, OtpPurpose $purpose) => $query->where('purpose', $purpose->value))
            ->update(['attempts' => DB::raw('attempts + 1'), 'updated_at' => now()]);

        $challenge = LoginChallenge::query()->find($challengeId);

        if ($challenge === null || ($purpose !== null && $challenge->purpose !== $purpose)) {
            return new OtpVerification(OtpStatus::Invalid);
        }

        if ($counted === 0) {
            return new OtpVerification($this->failureStatus($challenge), $challenge);
        }

        $code = preg_replace('/\D/', '', $code) ?? '';

        $fixed = $challenge->deliverable && $this->matchesFixedCode($challenge, $code);

        if (! $challenge->deliverable || (! $fixed && ! hash_equals($challenge->verifier, $this->verifier($challenge->id, $challenge->purpose, $code)))) {
            return new OtpVerification(
                $challenge->attempts >= $challenge->max_attempts ? OtpStatus::TooManyAttempts : OtpStatus::Invalid,
                $challenge,
            );
        }

        // Consume atomically: only one request can win the code...
        $consumed = LoginChallenge::query()
            ->whereKey($challenge->id)
            ->whereNull('consumed_at')
            ->whereNull('invalidated_at')
            ->update(['consumed_at' => now(), 'updated_at' => now()]);

        if ($consumed === 0) {
            return new OtpVerification(OtpStatus::Invalid, $challenge);
        }

        if ($fixed) {
            audit('auth.fixed_code_used', $challenge->user_id ? $challenge->user : null, [
                'email' => $challenge->email,
                'purpose' => $challenge->purpose->value,
            ]);
        }

        return new OtpVerification(OtpStatus::Verified, $challenge->refresh(), $fixed);
    }

    /**
     * Whether the fixed code is active: STARTER_OTP_FIXED_CODE and
     * STARTER_OTP_FIXED_EMAILS set, never in production.
     *
     * @return list<string> the emails that may use it
     */
    public static function fixedCodeEmails(): array
    {
        $code = (string) config('starter.auth.otp.fixed_code');

        if (app()->isProduction() || ! preg_match('/^\d{4,10}$/', $code)) {
            return [];
        }

        return array_values(array_filter(array_map(
            fn (string $email) => mb_strtolower(trim($email)),
            explode(',', (string) config('starter.auth.otp.fixed_emails')),
        )));
    }

    /**
     * The fixed code only logs in or confirms existing accounts of the
     * configured emails; registration and invitations keep the emailed code.
     */
    protected function matchesFixedCode(LoginChallenge $challenge, string $code): bool
    {
        return in_array($challenge->purpose, [OtpPurpose::Login, OtpPurpose::Confirm], true)
            && $challenge->user_id !== null
            && in_array(mb_strtolower($challenge->email), self::fixedCodeEmails(), true)
            && hash_equals((string) config('starter.auth.otp.fixed_code'), $code);
    }

    /**
     * Get the number of digits of the codes.
     */
    public function length(): int
    {
        return max(4, min(10, (int) config('starter.auth.otp.length', 6)));
    }

    /**
     * Get the minutes a code is valid for.
     */
    public function ttl(): int
    {
        return max(1, (int) config('starter.auth.otp.ttl', 5));
    }

    public function maxAttempts(): int
    {
        return max(1, (int) config('starter.auth.otp.max_attempts', 5));
    }

    /**
     * Get the seconds between two codes for the same email and purpose.
     */
    public function resendCooldown(): int
    {
        return max(0, (int) config('starter.auth.otp.resend_cooldown', 60));
    }

    public static function normalizeEmail(string $email): string
    {
        return Str::lower(trim($email));
    }

    /**
     * @param  array{user_id?: int|null, invitation_id?: int|null, deliverable?: bool, payload?: array<string, mixed>|null, ip_address?: string|null, user_agent?: string|null}  $attributes
     */
    protected function createChallenge(OtpPurpose $purpose, string $email, array $attributes): LoginChallenge
    {
        $code = $this->generateCode();
        $challenge = new LoginChallenge;
        $challenge->id = $challenge->newUniqueId();

        $challenge->forceFill([
            'purpose' => $purpose,
            'email' => $email,
            'user_id' => $attributes['user_id'] ?? null,
            'invitation_id' => $attributes['invitation_id'] ?? null,
            'verifier' => $this->verifier($challenge->id, $purpose, $code),
            'deliverable' => $attributes['deliverable'] ?? true,
            'max_attempts' => $this->maxAttempts(),
            'payload' => $attributes['payload'] ?? null,
            'ip_address' => $attributes['ip_address'] ?? null,
            'user_agent' => isset($attributes['user_agent']) ? Str::limit($attributes['user_agent'], 500, '') : null,
            'expires_at' => now()->addMinutes($this->ttl()),
            'resend_available_at' => now()->addSeconds($this->resendCooldown()),
        ]);

        DB::transaction(function () use ($challenge, $purpose, $email) {
            // Only the most recent code of an email and purpose is valid...
            LoginChallenge::query()
                ->where('email', $email)
                ->where('purpose', $purpose->value)
                ->whereNull('consumed_at')
                ->whereNull('invalidated_at')
                ->update(['invalidated_at' => now(), 'updated_at' => now()]);

            $challenge->save();
        });

        if ($challenge->deliverable) {
            $this->deliver($challenge, $code);
        }

        return $challenge;
    }

    /**
     * Send the code right away: login must not wait behind busy queues.
     */
    protected function deliver(LoginChallenge $challenge, string $code): void
    {
        $notification = new OneTimeCodeNotification($code, $challenge->purpose, $this->ttl());
        $user = $challenge->user;

        if ($user instanceof Model && method_exists($user, 'notifyNow')) {
            $user->notifyNow($notification);

            return;
        }

        Notification::route('mail', $challenge->email)->notifyNow($notification);
    }

    protected function verifier(string $challengeId, OtpPurpose $purpose, string $code): string
    {
        return hash_hmac('sha256', $challengeId.'|'.$purpose->value.'|'.$code, $this->secret());
    }

    protected function secret(): string
    {
        $secret = config('starter.auth.otp.secret') ?: config('app.key');

        return 'starter-otp:'.(is_string($secret) ? $secret : '');
    }

    protected function generateCode(): string
    {
        $length = $this->length();

        return str_pad((string) random_int(0, (10 ** $length) - 1), $length, '0', STR_PAD_LEFT);
    }

    protected function failureStatus(LoginChallenge $challenge): OtpStatus
    {
        return match (true) {
            $challenge->attempts >= $challenge->max_attempts => OtpStatus::TooManyAttempts,
            $challenge->expires_at->isPast() => OtpStatus::Expired,
            default => OtpStatus::Invalid,
        };
    }
}
