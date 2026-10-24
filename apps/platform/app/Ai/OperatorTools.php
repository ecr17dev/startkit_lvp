<?php

namespace App\Ai;

use App\Ai\Tools\PermissionsList;
use App\Ai\Tools\RolesCreate;
use App\Ai\Tools\RolesDelete;
use App\Ai\Tools\RolesGet;
use App\Ai\Tools\RolesList;
use App\Ai\Tools\RolesSetPermissions;
use App\Ai\Tools\RolesUpdate;
use App\Ai\Tools\UsersAssignRole;
use App\Ai\Tools\UsersCreate;
use App\Ai\Tools\UsersDelete;
use App\Ai\Tools\UsersDisable;
use App\Ai\Tools\UsersGet;
use App\Ai\Tools\UsersInvite;
use App\Ai\Tools\UsersList;
use App\Ai\Tools\UsersRemoveRole;
use App\Ai\Tools\UsersUpdate;
use Starter\Ai\Agents\ToolDefinition;
use Starter\Ai\Agents\ToolKind;
use Starter\Ai\Agents\ToolRegistry;

/**
 * Tools that let an agent operate users, roles and permissions. Each one
 * calls the same Actions (policies, delegation, audit) as the panel and the
 * API. The permission only decides whether the tool is offered.
 */
class OperatorTools
{
    public static function register(ToolRegistry $registry): void
    {
        $tools = [
            ['users_list', UsersList::class, 'users', 'Search users', ToolKind::Read, 'users.read'],
            ['users_get', UsersGet::class, 'users', 'Get a user', ToolKind::Read, 'users.read'],
            ['users_create', UsersCreate::class, 'users', 'Create a user', ToolKind::Write, 'users.create'],
            ['users_invite', UsersInvite::class, 'users', 'Invite a user', ToolKind::Write, 'users.invite'],
            ['users_update', UsersUpdate::class, 'users', 'Update a user', ToolKind::Write, 'users.update'],
            ['users_disable', UsersDisable::class, 'users', 'Disable or enable a user', ToolKind::Sensitive, 'users.disable'],
            ['users_delete', UsersDelete::class, 'users', 'Delete a user', ToolKind::Sensitive, 'users.delete'],
            ['users_assign_role', UsersAssignRole::class, 'users', 'Assign a role', ToolKind::Sensitive, 'users.update'],
            ['users_remove_role', UsersRemoveRole::class, 'users', 'Remove a role', ToolKind::Sensitive, 'users.update'],
            ['roles_list', RolesList::class, 'roles', 'List roles', ToolKind::Read, 'roles.read'],
            ['roles_get', RolesGet::class, 'roles', 'Get a role', ToolKind::Read, 'roles.read'],
            ['roles_create', RolesCreate::class, 'roles', 'Create a role', ToolKind::Sensitive, 'roles.manage'],
            ['roles_update', RolesUpdate::class, 'roles', 'Rename a role', ToolKind::Sensitive, 'roles.manage'],
            ['roles_delete', RolesDelete::class, 'roles', 'Delete a role', ToolKind::Sensitive, 'roles.manage'],
            ['roles_set_permissions', RolesSetPermissions::class, 'permissions', 'Set role permissions', ToolKind::Sensitive, 'permissions.manage'],
            ['permissions_list', PermissionsList::class, 'permissions', 'List permissions', ToolKind::Read, 'roles.read'],
        ];

        foreach ($tools as [$name, $class, $group, $label, $kind, $permission]) {
            $registry->register(new ToolDefinition($name, $class, $group, $label, $kind, $permission));
        }
    }
}
