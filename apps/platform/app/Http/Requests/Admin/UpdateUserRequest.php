<?php

namespace App\Http\Requests\Admin;

use App\Actions\Users\UpdateUser;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

class UpdateUserRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $user = $this->route('user');

        return UpdateUser::rules($user instanceof User ? $user : null);
    }
}
