<?php

namespace Starter\Identity;

use Illuminate\Database\Eloquent\Model;
use Starter\Identity\Registration\RegistrationMode;

/**
 * Entry point to the identity configuration.
 */
final class Identity
{
    public const METHOD_OTP = 'email-otp';

    public const METHOD_OTP_PASSWORD = 'email-otp+password';

    /**
     * Determine whether password based flows (login, resets, password
     * confirmation) are enabled. This is a deployment decision taken in the
     * environment (STARTER_AUTH_METHOD), never from the panel.
     */
    public static function passwordsEnabled(): bool
    {
        return config('starter.auth.method') === self::METHOD_OTP_PASSWORD;
    }

    /**
     * Get the registration mode persisted from the panel.
     */
    public static function registrationMode(): RegistrationMode
    {
        $value = settings('auth.registration', config('starter.auth.registration'));

        return RegistrationMode::tryFrom(is_string($value) ? $value : '') ?? RegistrationMode::InviteOnly;
    }

    /**
     * Get the user model class.
     *
     * @return class-string<Model>
     */
    public static function userModel(): string
    {
        /** @var class-string<Model> */
        return config('auth.providers.users.model');
    }
}
