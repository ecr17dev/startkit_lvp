<?php

namespace Starter\Identity\Authorization;

use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Starter\Identity\Permissions;

/**
 * Writes the permission catalog into the permission tables.
 *
 * Idempotent: system roles get their default permissions when created; the
 * owner role always receives every permission. Changes made from the panel
 * to the other roles are kept.
 */
class PermissionSynchronizer
{
    /**
     * @return array{created: list<string>, removed: list<string>}
     */
    public function sync(bool $prune = false): array
    {
        $registrar = app(PermissionRegistrar::class);
        $registrar->forgetCachedPermissions();

        $catalog = Permissions::all();
        $existing = Permission::query()->where('guard_name', 'web')->pluck('name')->all();
        $created = array_values(array_diff($catalog, $existing));
        $removed = [];

        foreach ($created as $name) {
            Permission::query()->create(['name' => $name, 'guard_name' => 'web']);
        }

        if ($prune) {
            $removed = array_values(array_diff($existing, $catalog));
            Permission::query()->where('guard_name', 'web')->whereIn('name', $removed)->delete();
        }

        $registrar->forgetCachedPermissions();

        foreach (Permissions::defaultRoles() as $name => $permissions) {
            $role = Role::findOrCreate($name, 'web');

            if ($name === Permissions::OWNER) {
                $role->syncPermissions($permissions);
            } elseif ($role->wasRecentlyCreated) {
                $role->givePermissionTo($permissions);
            }
        }

        $registrar->forgetCachedPermissions();

        return ['created' => $created, 'removed' => $removed];
    }
}
