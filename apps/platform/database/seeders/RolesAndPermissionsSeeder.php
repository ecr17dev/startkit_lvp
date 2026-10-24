<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Starter\Identity\Authorization\PermissionSynchronizer;

class RolesAndPermissionsSeeder extends Seeder
{
    /**
     * Create the permissions and system roles (same as `starter:permissions`).
     */
    public function run(PermissionSynchronizer $synchronizer): void
    {
        $synchronizer->sync();
    }
}
