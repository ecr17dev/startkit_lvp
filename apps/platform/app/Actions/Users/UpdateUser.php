<?php

namespace App\Actions\Users;

use App\Actions\Concerns\ChecksDelegation;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Starter\Identity\Identity;
use Starter\Identity\Permissions;

/**
 * Update the profile and roles of a user. Only the given keys change.
 */
class UpdateUser
{
    use ChecksDelegation;

    /**
     * @return array<string, mixed>
     */
    public static function rules(?User $user = null): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => ['sometimes', 'required', 'string', 'lowercase', 'email', 'max:255', Rule::unique(User::class)->ignore($user?->id)],
            'password' => Identity::passwordsEnabled()
                ? ['nullable', 'string', Password::defaults()]
                : ['prohibited'],
            'locale' => ['sometimes', 'nullable', 'string', Rule::in(array_keys((array) config('starter.locales')))],
            'email_verified' => ['sometimes', 'boolean'],
            'roles' => ['sometimes', 'array'],
            'roles.*' => ['string', 'distinct', Rule::exists('roles', 'name')],
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function handle(User $actor, User $user, array $input): User
    {
        Gate::forUser($actor)->authorize('update', $user);

        /** @var array{name?: string, email?: string, password?: string|null, locale?: string|null, email_verified?: bool, roles?: list<string>} $data */
        $data = Validator::make($input, self::rules($user))->validate();
        $currentRoles = $user->getRoleNames()->all();
        $roles = $data['roles'] ?? null;

        $changedRoles = $roles === null ? [] : array_merge(array_diff($roles, $currentRoles), array_diff($currentRoles, $roles));

        if ($roles !== null && $changedRoles !== []) {
            Gate::forUser($actor)->authorize('assignRoles', $user);

            $this->ensureCanGrantRoles($actor, $changedRoles);

            if (in_array(Permissions::OWNER, $currentRoles, true) && ! in_array(Permissions::OWNER, $roles, true)) {
                $this->ensureNotLastOwner($user, 'roles', __('At least one active owner is required.'));
            }
        }

        return DB::transaction(function () use ($actor, $user, $data, $roles, $currentRoles, $changedRoles) {
            $user->fill(Arr::only($data, ['name', 'email', 'locale']));

            if (filled($data['password'] ?? null)) {
                $user->password = $data['password'];
            }

            if ($user->isDirty('email')) {
                $user->email_verified_at = null;
            }

            if (($data['email_verified'] ?? false) && $user->email_verified_at === null) {
                $user->email_verified_at = now();
            }

            $changes = array_keys(Arr::except($user->getDirty(), ['updated_at', 'remember_token']));
            $user->save();

            if ($roles !== null) {
                $user->syncRoles($roles);
            }

            $properties = array_filter([
                'changed' => $changes,
                'roles' => $changedRoles !== [] ? ['from' => $currentRoles, 'to' => $roles] : null,
            ]);

            if ($properties !== []) {
                audit('users.updated', $user, $properties, $actor);
            }

            return $user;
        });
    }
}
