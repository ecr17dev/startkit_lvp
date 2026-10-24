<?php

namespace App\Http\Controllers\Admin\Settings;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Role;
use Starter\Administration\Settings\SettingsManager;

class AuthSettingsController extends Controller
{
    public function __construct(protected SettingsManager $settings) {}

    public function edit(): Response
    {
        return Inertia::render('admin/settings/Auth', [
            'settings' => $this->settings->group('auth'),
            'roles' => Role::query()->orderBy('name')->pluck('name'),
            'mailConfigured' => ! in_array(config('mail.default'), ['log', 'array'], true),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'password_login' => ['required', 'boolean'],
            'code_login' => ['required', 'boolean'],
            'registration' => ['required', 'boolean'],
            'default_role' => ['nullable', 'string', Rule::exists('roles', 'name')],
            'code_length' => ['required', 'integer', 'between:4,10'],
            'code_ttl' => ['required', 'integer', 'between:1,60'],
            'code_max_attempts' => ['required', 'integer', 'between:1,10'],
        ]);

        if (! $validated['password_login'] && ! $validated['code_login']) {
            throw ValidationException::withMessages([
                'code_login' => __('At least one login method must be enabled.'),
            ]);
        }

        $this->settings->setGroup('auth', $validated);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Settings saved.')]);

        return back();
    }
}
