<?php

namespace App\Policies;

use App\Models\User;
use Spatie\Permission\Models\Role;
use Starter\Identity\Permissions;

/**
 * The owner role is immutable (it always holds every permission) and the
 * system roles cannot be renamed nor deleted. Granting permissions is
 * further limited by the delegation rules (Starter\Identity\Authorization\Delegation).
 */
class RolePolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->can('roles.read');
    }

    public function view(User $actor, Role $role): bool
    {
        return $actor->can('roles.read');
    }

    public function create(User $actor): bool
    {
        return $actor->can('roles.manage');
    }

    public function update(User $actor, Role $role): bool
    {
        return $role->name !== Permissions::OWNER && $actor->can('roles.manage');
    }

    /**
     * Edit the role permissions from the permission matrix.
     */
    public function updatePermissions(User $actor, Role $role): bool
    {
        return $role->name !== Permissions::OWNER && $actor->can('permissions.manage');
    }

    public function delete(User $actor, Role $role): bool
    {
        return ! in_array($role->name, Permissions::systemRoles(), true) && $actor->can('roles.manage');
    }
}
