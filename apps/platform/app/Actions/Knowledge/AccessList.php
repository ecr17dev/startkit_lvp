<?php

namespace App\Actions\Knowledge;

use App\Models\User;
use Closure;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Starter\Knowledge\Support\Principals;

/**
 * Validation of access lists (principals) written by administrators.
 */
final class AccessList
{
    /**
     * @return array<string, mixed>
     */
    public static function rules(string $key, bool $required = true): array
    {
        return [
            $key => [$required ? 'required' : 'nullable', 'array', $required ? 'min:1' : 'min:0', 'max:50'],
            $key.'.*' => ['required', 'string', 'distinct', 'max:120', function (string $attribute, mixed $value, Closure $fail) {
                if (! is_string($value) || ! self::exists($value)) {
                    $fail(__('The principal :value is not valid.', ['value' => is_string($value) ? $value : '']));
                }
            }],
        ];
    }

    public static function exists(string $principal): bool
    {
        if (! Principals::valid($principal)) {
            return false;
        }

        [$kind, $value] = array_pad(explode(':', $principal, 2), 2, null);

        return match ($kind) {
            Principals::EVERYONE => true,
            'user' => User::query()->whereKey((int) $value)->exists(),
            'role' => Role::query()->where('name', $value)->exists(),
            'permission' => Permission::query()->where('name', $value)->exists(),
            default => false,
        };
    }
}
