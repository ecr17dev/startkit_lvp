<?php

namespace App\Policies;

use App\Models\User;

class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('users.view');
    }

    public function view(User $user, User $model): bool
    {
        return $user->can('users.view');
    }

    public function create(User $user): bool
    {
        return $user->can('users.create');
    }

    /**
     * Only super administrators can modify other super administrators.
     */
    public function update(User $user, User $model): bool
    {
        if ($model->isSuperAdmin() && ! $user->isSuperAdmin()) {
            return false;
        }

        return $user->can('users.update');
    }

    /**
     * Users cannot delete themselves from the admin panel, nor super administrators.
     */
    public function delete(User $user, User $model): bool
    {
        if ($user->is($model) || ($model->isSuperAdmin() && ! $user->isSuperAdmin())) {
            return false;
        }

        return $user->can('users.delete');
    }
}
