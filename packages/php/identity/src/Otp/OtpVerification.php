<?php

namespace Starter\Identity\Otp;

use Starter\Identity\Models\LoginChallenge;

/**
 * Outcome of a code verification.
 */
final readonly class OtpVerification
{
    public function __construct(
        public OtpStatus $status,
        public ?LoginChallenge $challenge = null,
        // Verified with the fixed code of a test account (never in production)...
        public bool $fixedCode = false,
    ) {}

    public function verified(): bool
    {
        return $this->status === OtpStatus::Verified;
    }
}
