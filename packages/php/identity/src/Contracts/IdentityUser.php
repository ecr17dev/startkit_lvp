<?php

namespace Starter\Identity\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Collection;

/**
 * What the identity module needs from the application user model.
 *
 * App\Models\User fulfils it with spatie/laravel-permission's HasRoles.
 */
interface IdentityUser extends Authenticatable
{
    public function isOwner(): bool;

    public function isActive(): bool;

    /**
     * Get the names of every permission of the user, direct or through roles.
     *
     * @return list<string>
     */
    public function permissionNames(): array;

    /**
     * @param  mixed  $roles
     */
    public function hasRole($roles, ?string $guard = null): bool;

    /**
     * @param  mixed  ...$roles
     */
    public function assignRole(...$roles): static;

    /**
     * @param  mixed  ...$roles
     */
    public function syncRoles(...$roles): static;

    /**
     * @return Collection<int, string>
     */
    public function getRoleNames(): Collection;
}
