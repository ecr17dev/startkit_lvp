<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Traits\HasRoles;
use Starter\Identity\Contracts\IdentityUser;
use Starter\Identity\Permissions;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property CarbonInterface|null $email_verified_at
 * @property string|null $password
 * @property bool $is_active
 * @property string|null $locale
 * @property CarbonInterface|null $last_login_at
 * @property string|null $last_login_ip
 * @property CarbonInterface|null $disabled_at
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property CarbonInterface|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property CarbonInterface|null $deleted_at
 */
#[Fillable(['name', 'email', 'password', 'locale'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements IdentityUser, MustVerifyEmail, PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasRoles, Notifiable, PasskeyAuthenticatable, SoftDeletes, TwoFactorAuthenticatable;

    /**
     * Roles and permissions belong to the "web" guard, also for API requests
     * authenticated by Sanctum (spatie/laravel-permission).
     */
    protected string $guard_name = 'web';

    /**
     * The model's default values for attributes.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'name' => '',
        'is_active' => true,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'last_login_at' => 'datetime',
            'disabled_at' => 'datetime',
            'two_factor_confirmed_at' => 'datetime',
        ];
    }

    public function isOwner(): bool
    {
        return $this->hasRole(Permissions::OWNER);
    }

    public function isActive(): bool
    {
        return $this->is_active && ! $this->trashed();
    }

    /**
     * Determine whether the user can log in with a password.
     */
    public function hasPassword(): bool
    {
        return filled($this->password);
    }

    /**
     * Get the names of every permission the user has, directly or through roles.
     *
     * @return list<string>
     */
    public function permissionNames(): array
    {
        return array_values($this->getAllPermissions()
            ->map(fn (Permission $permission): string => $permission->name)
            ->unique()
            ->sort()
            ->values()
            ->all());
    }
}
