<?php

namespace App\Actions\Users;

use App\Actions\Concerns\ChecksDelegation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Starter\Identity\Identity;

/**
 * Create a user from the panel, the API or an AI tool.
 */
class CreateUser
{
    use ChecksDelegation;

    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', Rule::unique(User::class)],
            'password' => Identity::passwordsEnabled()
                ? ['nullable', 'string', Password::defaults()]
                : ['prohibited'],
            'locale' => ['nullable', 'string', Rule::in(array_keys((array) config('starter.locales')))],
            'email_verified' => ['boolean'],
            'roles' => ['array'],
            'roles.*' => ['string', 'distinct', Rule::exists('roles', 'name')],
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function handle(User $actor, array $input): User
    {
        Gate::forUser($actor)->authorize('create', User::class);

        /** @var array{name: string, email: string, password?: string|null, locale?: string|null, email_verified?: bool, roles?: list<string>} $data */
        $data = Validator::make($input, self::rules())->validate();
        $roles = $data['roles'] ?? [];

        $this->ensureCanGrantRoles($actor, $roles);

        return DB::transaction(function () use ($actor, $data, $roles) {
            $user = new User([
                'name' => $data['name'],
                'email' => $data['email'],
                'locale' => $data['locale'] ?? null,
            ]);

            if (filled($data['password'] ?? null)) {
                $user->password = $data['password'];
            }

            if ($data['email_verified'] ?? false) {
                $user->email_verified_at = now();
            }

            $user->save();
            $user->syncRoles($roles);

            audit('users.created', $user, ['email' => $user->email, 'roles' => $roles], $actor);

            return $user;
        });
    }
}
