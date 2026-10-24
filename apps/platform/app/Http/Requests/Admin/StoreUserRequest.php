<?php

namespace App\Http\Requests\Admin;

use App\Actions\Users\CreateUser;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation is shared with the action (panel, API and AI tools).
 */
class StoreUserRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return CreateUser::rules();
    }
}
