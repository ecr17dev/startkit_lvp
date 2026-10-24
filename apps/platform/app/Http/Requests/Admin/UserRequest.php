<?php

namespace App\Http\Requests\Admin;

use App\Models\User;
use App\Support\Permissions;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class UserRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /** @var User|null $user */
        $user = $this->route('user');

        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required', 'string', 'lowercase', 'email', 'max:255',
                Rule::unique(User::class)->ignore($user?->id),
            ],
            'password' => ['nullable', 'string', Password::defaults()],
            'is_active' => ['boolean'],
            'email_verified' => ['boolean'],
            'locale' => ['nullable', 'string', Rule::in(array_keys((array) config('starter.locales')))],
            'roles' => ['array'],
            'roles.*' => [
                'string',
                Rule::exists('roles', 'name'),
                function (string $attribute, mixed $value, Closure $fail) {
                    if ($value === Permissions::SUPER_ADMIN && ! $this->user()?->isSuperAdmin()) {
                        $fail(__('Only super administrators can assign the super administrator role.'));
                    }
                },
            ],
        ];
    }
}
