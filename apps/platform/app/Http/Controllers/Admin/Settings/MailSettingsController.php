<?php

namespace App\Http\Controllers\Admin\Settings;

use App\Http\Controllers\Controller;
use App\Mail\TestMail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Starter\Administration\Settings\SettingsManager;
use Throwable;

class MailSettingsController extends Controller
{
    public function __construct(protected SettingsManager $settings) {}

    public function edit(): Response
    {
        $settings = $this->settings->group('mail');

        return Inertia::render('admin/settings/Mail', [
            'settings' => [
                ...Arr::except($settings, ['password']),
                'has_password' => filled($settings['password'] ?? null),
            ],
            'envMailer' => config('starter.env_mailer'),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'mailer' => ['required', Rule::in(['env', 'smtp', 'log'])],
            'host' => ['nullable', 'required_if:mailer,smtp', 'string', 'max:255'],
            'port' => ['nullable', 'required_if:mailer,smtp', 'integer', 'between:1,65535'],
            'username' => ['nullable', 'string', 'max:255'],
            'password' => ['nullable', 'string', 'max:255'],
            'remove_password' => ['boolean'],
            'scheme' => ['nullable', Rule::in(['smtp', 'smtps'])],
            'from_address' => ['required', 'email', 'max:255'],
            'from_name' => ['required', 'string', 'max:255'],
        ]);

        $values = Arr::except($validated, ['password', 'remove_password']);

        // A blank password keeps the stored one...
        if (filled($validated['password'] ?? null)) {
            $values['password'] = $validated['password'];
        } elseif ($request->boolean('remove_password')) {
            $values['password'] = null;
        }

        $this->settings->setGroup('mail', $values);

        // Long running queue workers must reload the configuration...
        Artisan::call('queue:restart');

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Mail settings saved.')]);

        return back();
    }

    /**
     * Send a test email using the saved configuration.
     */
    public function test(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email', 'max:255'],
        ]);

        try {
            Mail::to($validated['email'])->send(new TestMail);
        } catch (Throwable $e) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('The test email could not be sent: :error', ['error' => $e->getMessage()])]);

            return back();
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Test email sent to :email.', ['email' => $validated['email']])]);

        return back();
    }
}
