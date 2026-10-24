<?php

namespace Database\Seeders;

use App\Support\Permissions;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolesAndPermissionsSeeder extends Seeder
{
    /**
     * Create the permissions and default roles. Safe to run many times.
     */
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (Permissions::all() as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        foreach (Permissions::defaultRoles() as $name => $permissions) {
            $role = Role::findOrCreate($name, 'web');

            // Only fill the permissions of freshly created roles so changes
            // made from the admin panel survive future syncs...
            if ($role->wasRecentlyCreated || $name === Permissions::ADMIN) {
                $role->givePermissionTo($permissions);
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
