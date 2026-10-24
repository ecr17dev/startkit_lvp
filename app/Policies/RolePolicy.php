<?php

namespace App\Policies;

use App\Models\User;
use App\Support\Permissions;
use Spatie\Permission\Models\Role;

class RolePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('roles.view');
    }

    public function view(User $user, Role $role): bool
    {
        return $user->can('roles.view');
    }

    public function create(User $user): bool
    {
        return $user->can('roles.create');
    }

    /**
     * The super administrator role always has every permission.
     */
    public function update(User $user, Role $role): bool
    {
        return $role->name !== Permissions::SUPER_ADMIN && $user->can('roles.update');
    }

    public function delete(User $user, Role $role): bool
    {
        return ! in_array($role->name, Permissions::protectedRoles(), true)
            && $user->can('roles.delete');
    }
}
