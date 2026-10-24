<?php

namespace Starter\Identity\Otp;

enum OtpStatus: string
{
    case Verified = 'verified';
    case Invalid = 'invalid';
    case Expired = 'expired';
    case TooManyAttempts = 'too_many_attempts';

    /**
     * Get the message shown to the user. It never reveals whether an
     * account exists for the email address.
     */
    public function message(): string
    {
        return match ($this) {
            self::Verified => __('Code verified.'),
            self::Invalid => __('The code is invalid or has expired.'),
            self::Expired => __('The code has expired. Request a new one.'),
            self::TooManyAttempts => __('Too many attempts. Request a new code.'),
        };
    }
}
