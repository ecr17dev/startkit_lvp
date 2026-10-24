<?php

namespace App\Actions\Concerns;

use App\Models\User;
use Illuminate\Validation\ValidationException;
use Starter\Identity\Authorization\Delegation;

trait ChecksDelegation
{
    /**
     * Ensure the actor can grant (or take away) every given role.
     *
     * @param  array<int, string>  $roles
     */
    protected function ensureCanGrantRoles(User $actor, array $roles, string $field = 'roles'): void
    {
        $delegation = app(Delegation::class);

        foreach ($roles as $role) {
            if (! $delegation->canGrantRole($actor, $role)) {
                throw ValidationException::withMessages([
                    $field => __('You cannot grant the :role role: it has permissions you do not hold.', ['role' => $role]),
                ]);
            }
        }
    }

    /**
     * Ensure the actor holds every given permission.
     *
     * @param  array<int, string>  $permissions
     */
    protected function ensureCanGrantPermissions(User $actor, array $permissions, string $field = 'permissions'): void
    {
        $missing = app(Delegation::class)->ungrantablePermissions($actor, $permissions);

        if ($missing !== []) {
            throw ValidationException::withMessages([
                $field => __('You cannot grant permissions you do not hold: :permissions.', ['permissions' => implode(', ', $missing)]),
            ]);
        }
    }

    /**
     * Ensure the user is not the last active owner.
     */
    protected function ensureNotLastOwner(User $user, string $field, string $message): void
    {
        if (app(Delegation::class)->isLastOwner($user)) {
            throw ValidationException::withMessages([$field => $message]);
        }
    }
}
