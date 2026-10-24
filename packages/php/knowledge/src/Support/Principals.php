<?php

namespace Starter\Knowledge\Support;

use Illuminate\Database\Eloquent\Model;

/**
 * Access principals of a user, matched against the access lists of
 * collections and documents inside the retrieval query:
 *
 *   everyone            any authenticated user of the installation
 *   user:{id}           a specific user
 *   role:{name}         users with the role
 *   permission:{name}   users with the permission
 */
final class Principals
{
    public const EVERYONE = 'everyone';

    /**
     * @return list<string>
     */
    public static function for(mixed $user): array
    {
        if (! $user instanceof Model) {
            return [];
        }

        $principals = [self::EVERYONE, 'user:'.$user->getKey()];

        if (method_exists($user, 'getRoleNames')) {
            foreach ($user->getRoleNames() as $role) {
                $principals[] = 'role:'.$role;
            }
        }

        if (method_exists($user, 'getAllPermissions')) {
            foreach ($user->getAllPermissions() as $permission) {
                $principals[] = 'permission:'.$permission->name;
            }
        }

        return array_values(array_unique($principals));
    }

    /**
     * Validation of a principal written by an administrator.
     */
    public static function valid(string $principal): bool
    {
        return $principal === self::EVERYONE
            || preg_match('/^(user:\d+|role:[\w.\- ]{1,100}|permission:[\w.\-]{1,100})$/u', $principal) === 1;
    }
}
