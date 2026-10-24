<?php

namespace App\Actions\Roles;

use App\Actions\Concerns\ChecksDelegation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Role;
use Starter\Identity\Permissions;

class CreateRole
{
    use ChecksDelegation;

    /**
     * @return array<string, mixed>
     */
    public static function rules(?Role $role = null): array
    {
        return [
            'name' => ['required', 'string', 'max:100', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', Rule::unique('roles', 'name')->ignore($role?->id)],
            'permissions' => ['array'],
            'permissions.*' => ['string', 'distinct', Rule::in(Permissions::all())],
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function handle(User $actor, array $input): Role
    {
        Gate::forUser($actor)->authorize('create', Role::class);

        $input['name'] = str((string) ($input['name'] ?? ''))->trim()->lower()->slug()->toString();

        /** @var array{name: string, permissions?: list<string>} $data */
        $data = Validator::make($input, self::rules())->validate();
        $permissions = $data['permissions'] ?? [];

        $this->ensureCanGrantPermissions($actor, $permissions);

        return DB::transaction(function () use ($actor, $data, $permissions) {
            $role = Role::query()->create(['name' => $data['name'], 'guard_name' => 'web']);
            $role->syncPermissions($permissions);

            audit('roles.created', $role, ['name' => $role->name, 'permissions' => $permissions], $actor);

            return $role;
        });
    }
}
