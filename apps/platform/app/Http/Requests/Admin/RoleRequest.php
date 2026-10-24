<?php

namespace App\Http\Requests\Admin;

use App\Actions\Roles\CreateRole;
use Illuminate\Foundation\Http\FormRequest;
use Spatie\Permission\Models\Role;

class RoleRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if ($this->has('name')) {
            $this->merge(['name' => str((string) $this->input('name'))->trim()->lower()->slug()->toString()]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $role = $this->route('role');
        $rules = CreateRole::rules($role instanceof Role ? $role : null);

        if ($this->isMethod('PUT') || $this->isMethod('PATCH')) {
            $rules['name'] = ['sometimes', ...$rules['name']];
        }

        return $rules;
    }
}
