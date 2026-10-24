<?php

namespace App\Actions\Roles;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\Models\Role;

class DeleteRole
{
    public function handle(User $actor, Role $role): void
    {
        Gate::forUser($actor)->authorize('delete', $role);

        $name = $role->name;
        $users = $role->users()->count();
        $role->delete();

        audit('roles.deleted', $role, ['name' => $name, 'users' => $users], $actor);
    }
}
