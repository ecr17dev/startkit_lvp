<?php

namespace Starter\Identity;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
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

    /**
     * Get the active users holding a permission, directly or through a role.
     *
     * @return Collection<int, Model>
     */
    public static function activeUsersWithPermission(string $permission): Collection
    {
        $model = self::userModel();

        return $model::query()
            ->where('is_active', true)
            ->where(fn (Builder $query) => $query
                ->whereHas('permissions', fn (Builder $permissions) => $permissions->where('name', $permission))
                ->orWhereHas('roles.permissions', fn (Builder $permissions) => $permissions->where('name', $permission)))
            ->get();
    }
}
