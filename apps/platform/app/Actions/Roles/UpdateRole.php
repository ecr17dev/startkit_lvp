<?php

namespace App\Actions\Roles;

use App\Actions\Concerns\ChecksDelegation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;
use Starter\Identity\Permissions;

/**
 * Rename a role and/or change its permissions. Only the permissions that
 * change must be held by the actor.
 */
class UpdateRole
{
    use ChecksDelegation;

    /**
     * @param  array<string, mixed>  $input
     * @param  string  $ability  "update" (role form) or "updatePermissions" (permission matrix)
     */
    public function handle(User $actor, Role $role, array $input, string $ability = 'update'): Role
    {
        Gate::forUser($actor)->authorize($ability, $role);

        if (array_key_exists('name', $input)) {
            $input['name'] = str((string) $input['name'])->trim()->lower()->slug()->toString();
        }

        /** @var array{name?: string, permissions?: list<string>} $data */
        $data = Validator::make($input, [
            'name' => ['sometimes', ...CreateRole::rules($role)['name']],
            'permissions' => ['sometimes', 'array'],
            'permissions.*' => ['string', 'distinct', Rule::in(Permissions::all())],
        ])->validate();

        $name = $data['name'] ?? $role->name;

        if ($name !== $role->name && in_array($role->name, Permissions::systemRoles(), true)) {
            throw ValidationException::withMessages(['name' => __('System roles cannot be renamed.')]);
        }

        $current = $role->permissions->pluck('name')->all();
        $permissions = $data['permissions'] ?? $current;
        $changed = array_values(array_merge(array_diff($permissions, $current), array_diff($current, $permissions)));

        $this->ensureCanGrantPermissions($actor, $changed);

        return DB::transaction(function () use ($actor, $role, $name, $permissions, $changed) {
            $previousName = $role->name;
            $role->update(['name' => $name]);
            $role->syncPermissions($permissions);

            audit('roles.updated', $role, array_filter([
                'name' => $previousName !== $name ? ['from' => $previousName, 'to' => $name] : null,
                'granted' => array_values(array_intersect($changed, $permissions)),
                'revoked' => array_values(array_diff($changed, $permissions)),
            ]), $actor);

            return $role->load('permissions');
        });
    }
}
