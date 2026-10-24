<?php

namespace App\Http\Requests\Admin;

use App\Actions\Invitations\InviteUser;
use Illuminate\Foundation\Http\FormRequest;

class InviteUserRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => mb_strtolower(trim($this->input('email')))]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return InviteUser::rules();
    }
}
