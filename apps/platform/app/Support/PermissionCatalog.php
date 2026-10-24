<?php

namespace App\Support;

use Starter\Identity\Permissions;

/**
 * Presents the permission catalog in the panel.
 */
class PermissionCatalog
{
    /**
     * @return list<array{group: string, permissions: list<array{name: string, description: string}>}>
     */
    public static function groups(): array
    {
        $groups = [];

        foreach (Permissions::grouped() as $group => $permissions) {
            $items = [];

            foreach ($permissions as $name => $description) {
                $translated = __($description);
                $items[] = ['name' => $name, 'description' => is_string($translated) ? $translated : $description];
            }

            $groups[] = ['group' => $group, 'permissions' => $items];
        }

        return $groups;
    }
}
