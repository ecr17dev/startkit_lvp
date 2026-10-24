<?php

namespace App\Http\Controllers\Admin\Settings;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Role;
use Starter\Administration\Settings\SettingsManager;
use Starter\Identity\Identity;
use Starter\Identity\Otp\OtpBroker;
use Starter\Identity\Permissions;
use Starter\Identity\Registration\RegistrationMode;

/**
 * Registration policy. The login method and the one-time code values are
 * deployment decisions (environment) and are shown read only.
 */
class AuthSettingsController extends Controller
{
    public function __construct(protected SettingsManager $settings) {}

    public function edit(OtpBroker $broker): Response
    {
        return Inertia::render('admin/settings/Auth', [
            'settings' => [
                'registration' => Identity::registrationMode()->value,
                'default_role' => $this->settings->get('auth.default_role'),
            ],
            'roles' => $this->assignableRoles(),
            'registrationModes' => array_map(fn (RegistrationMode $mode) => $mode->value, RegistrationMode::cases()),
            'environment' => [
                'method' => config('starter.auth.method'),
                'passwords_enabled' => Identity::passwordsEnabled(),
                'otp_length' => $broker->length(),
                'otp_ttl' => $broker->ttl(),
                'otp_max_attempts' => $broker->maxAttempts(),
                'otp_resend_cooldown' => $broker->resendCooldown(),
                'invitation_ttl_days' => (int) config('starter.auth.invitation_ttl_days', 7),
            ],
            'mailConfigured' => ! in_array(config('mail.default'), ['log', 'array'], true),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'registration' => ['required', Rule::enum(RegistrationMode::class)],
            'default_role' => ['required', 'string', Rule::in($this->assignableRoles())],
        ]);

        $this->settings->setGroup('auth', $validated);

        audit('settings.updated', null, ['group' => 'auth', 'values' => $validated]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Settings saved.')]);

        return back();
    }

    /**
     * Roles that self registered users may receive: never owner nor admin.
     *
     * @return list<string>
     */
    protected function assignableRoles(): array
    {
        return array_values(Role::query()
            ->whereNotIn('name', [Permissions::OWNER, Permissions::ADMIN])
            ->orderBy('id')
            ->pluck('name')
            ->all());
    }
}
