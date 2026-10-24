<?php

namespace App\Policies;

use App\Models\User;

/**
 * Owners can only be managed by other owners. Nobody disables, deletes or
 * changes the roles of their own account from the administration.
 */
class UserPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->can('users.read');
    }

    public function view(User $actor, User $user): bool
    {
        return $actor->is($user) || $actor->can('users.read');
    }

    public function create(User $actor): bool
    {
        return $actor->can('users.create');
    }

    public function invite(User $actor): bool
    {
        return $actor->can('users.invite');
    }

    public function update(User $actor, User $user): bool
    {
        return $actor->can('users.update') && $this->canManage($actor, $user);
    }

    public function assignRoles(User $actor, User $user): bool
    {
        return ! $actor->is($user) && $this->update($actor, $user);
    }

    public function disable(User $actor, User $user): bool
    {
        return ! $actor->is($user) && $actor->can('users.disable') && $this->canManage($actor, $user);
    }

    public function delete(User $actor, User $user): bool
    {
        return ! $actor->is($user) && $actor->can('users.delete') && $this->canManage($actor, $user);
    }

    /**
     * View and revoke the sessions and tokens of the user.
     */
    public function manageSessions(User $actor, User $user): bool
    {
        return $actor->is($user) || $this->update($actor, $user);
    }

    protected function canManage(User $actor, User $user): bool
    {
        return ! $user->isOwner() || $actor->isOwner();
    }
}
