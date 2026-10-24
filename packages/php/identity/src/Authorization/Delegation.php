<?php

namespace Starter\Identity\Authorization;

use Illuminate\Support\Collection;
use Spatie\Permission\Models\Role;
use Starter\Identity\Contracts\IdentityUser;
use Starter\Identity\Identity;
use Starter\Identity\Permissions;

/**
 * Delegation rules shared by the panel, the API, the CLI and the AI tools.
 *
 * - Nobody grants a permission they do not hold (directly or in a role).
 * - Only owners grant the owner role, and the owner role is immutable.
 * - Nobody changes their own roles (no self escalation).
 * - The last active owner cannot be demoted, disabled nor deleted.
 */
class Delegation
{
    /**
     * Determine whether the actor can grant the role to someone.
     */
    public function canGrantRole(IdentityUser $actor, Role|string $role): bool
    {
        $role = $role instanceof Role ? $role : Role::query()->where('name', $role)->with('permissions')->first();

        if ($role === null) {
            return false;
        }

        if ($role->name === Permissions::OWNER) {
            return $actor->isOwner();
        }

        return $this->canGrantPermissions($actor, $role->permissions->pluck('name')->all());
    }

    /**
     * Determine whether the actor holds every given permission.
     *
     * @param  array<int, string>  $permissions
     */
    public function canGrantPermissions(IdentityUser $actor, array $permissions): bool
    {
        return array_diff($permissions, $actor->permissionNames()) === [];
    }

    /**
     * Get the permissions the actor is not allowed to grant.
     *
     * @param  array<int, string>  $permissions
     * @return list<string>
     */
    public function ungrantablePermissions(IdentityUser $actor, array $permissions): array
    {
        return array_values(array_diff($permissions, $actor->permissionNames()));
    }

    /**
     * Get the roles the actor can grant.
     *
     * @return Collection<int, Role>
     */
    public function grantableRoles(IdentityUser $actor): Collection
    {
        return Role::query()
            ->with('permissions')
            ->orderBy('id')
            ->get()
            ->filter(fn (Role $role) => $this->canGrantRole($actor, $role))
            ->values();
    }

    /**
     * Determine whether the user is the only active owner left.
     */
    public function isLastOwner(IdentityUser $user): bool
    {
        if (! $user->isOwner()) {
            return false;
        }

        $model = Identity::userModel();

        return $model::query()
            ->whereKeyNot($user->getAuthIdentifier())
            ->where('is_active', true)
            ->whereHas('roles', fn ($query) => $query->where('name', Permissions::OWNER))
            ->doesntExist();
    }
}
