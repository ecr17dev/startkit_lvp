<?php

namespace App\Ai\Tools\Concerns;

use App\Models\User;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

/**
 * Lookups and presentation shared by the operator tools. Identifiers come
 * from the arguments, but the actor and every check come from the server.
 */
trait InteractsWithDirectory
{
    protected function actor(): User
    {
        /** @var User */
        return $this->context->actor;
    }

    protected function findUser(int $id): User
    {
        return User::query()->with('roles')->find($id)
            ?? throw ValidationException::withMessages(['user_id' => "No user exists with id {$id}. Search first with users_list."]);
    }

    protected function findRole(string $name): Role
    {
        /** @var Role|null $role */
        $role = Role::query()->with('permissions')->where('name', $name)->first();

        return $role ?? throw ValidationException::withMessages(['role' => "No role exists named \"{$name}\". List them with roles_list."]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function presentUser(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'roles' => $user->getRoleNames()->values()->all(),
            'is_active' => $user->is_active,
            'email_verified' => $user->email_verified_at !== null,
            'last_login_at' => $user->last_login_at?->toIso8601String(),
            'url' => route('admin.users.edit', $user, false),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function presentRole(Role $role): array
    {
        return [
            'name' => $role->name,
            'permissions' => $role->permissions->pluck('name')->sort()->values()->all(),
            'users_count' => $role->users()->count(),
            'url' => route('admin.roles.edit', $role, false),
        ];
    }

    /**
     * Fingerprint of the state a change depends on.
     */
    protected function userVersion(User $user): string
    {
        return sha1(implode('|', [$user->updated_at?->toIso8601String(), $user->is_active ? '1' : '0', $user->getRoleNames()->sort()->implode(',')]));
    }

    protected function roleVersion(Role $role): string
    {
        return sha1(implode('|', [$role->name, $role->updated_at?->toIso8601String(), $role->permissions->pluck('name')->sort()->implode(',')]));
    }
}
