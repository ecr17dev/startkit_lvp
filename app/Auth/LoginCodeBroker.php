<?php

namespace App\Auth;

use App\Models\LoginCode;
use App\Models\User;
use App\Notifications\LoginCodeNotification;
use App\Settings\SettingsManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Issues and verifies the one-time codes used for passwordless login.
 */
class LoginCodeBroker
{
    public function __construct(protected SettingsManager $settings) {}

    /**
     * Send a new login code to the user owning the email, if any.
     *
     * The method returns silently for unknown or inactive accounts so
     * the response never reveals which emails are registered.
     */
    public function send(string $email, ?string $ip = null, ?string $userAgent = null): void
    {
        $user = $this->findUser($email);

        if ($user === null) {
            return;
        }

        $code = $this->generateCode();

        DB::transaction(function () use ($user, $code, $ip, $userAgent) {
            // Only the most recent code is valid...
            LoginCode::query()
                ->where('user_id', $user->getKey())
                ->whereNull('consumed_at')
                ->update(['consumed_at' => now()]);

            LoginCode::query()->create([
                'user_id' => $user->getKey(),
                'code_hash' => Hash::make($code),
                'expires_at' => now()->addMinutes($this->ttl()),
                'ip_address' => $ip,
                'user_agent' => $userAgent !== null ? Str::limit($userAgent, 500, '') : null,
            ]);
        });

        $user->notifyNow(new LoginCodeNotification($code, $this->ttl()));
    }

    /**
     * Verify the code and return the user it belongs to.
     */
    public function verify(string $email, string $code): ?User
    {
        $user = $this->findUser($email);

        if ($user === null) {
            return null;
        }

        $loginCode = LoginCode::query()
            ->where('user_id', $user->getKey())
            ->whereNull('consumed_at')
            ->latest('id')
            ->first();

        if ($loginCode === null || ! $loginCode->isUsable($this->maxAttempts())) {
            return null;
        }

        $loginCode->increment('attempts');

        if (! Hash::check($code, $loginCode->code_hash)) {
            return null;
        }

        $loginCode->forceFill(['consumed_at' => now()])->save();

        // Receiving the code proves the ownership of the email address...
        if ($user->email_verified_at === null) {
            $user->markEmailAsVerified();
        }

        return $user;
    }

    /**
     * Get the number of digits of the codes.
     */
    public function length(): int
    {
        return max(4, min(10, (int) $this->settings->get('auth.code_length', 6)));
    }

    /**
     * Get the minutes a code is valid for.
     */
    public function ttl(): int
    {
        return max(1, (int) $this->settings->get('auth.code_ttl', 10));
    }

    /**
     * Get the number of attempts allowed for a single code.
     */
    public function maxAttempts(): int
    {
        return max(1, (int) $this->settings->get('auth.code_max_attempts', 5));
    }

    protected function generateCode(): string
    {
        $length = $this->length();

        return str_pad((string) random_int(0, (10 ** $length) - 1), $length, '0', STR_PAD_LEFT);
    }

    protected function findUser(string $email): ?User
    {
        return User::query()
            ->where('email', Str::lower(trim($email)))
            ->where('is_active', true)
            ->first();
    }
}
