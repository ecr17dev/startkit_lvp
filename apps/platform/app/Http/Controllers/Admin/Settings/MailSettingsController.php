<?php

namespace App\Http\Controllers\Admin\Settings;

use App\Actions\Settings\UpdateMailSettings;
use App\Http\Controllers\Controller;
use App\Mail\TestMail;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Mail;
use Inertia\Inertia;
use Inertia\Response;
use Starter\Administration\Settings\MailCredentials;
use Starter\Administration\Settings\SettingsManager;
use Throwable;

class MailSettingsController extends Controller
{
    public function __construct(protected SettingsManager $settings) {}

    public function edit(): Response
    {
        $settings = $this->settings->group('mail');

        return Inertia::render('admin/settings/Mail', [
            'settings' => Arr::except($settings, UpdateMailSettings::SECRET_FIELDS),
            // Where each credential comes from, never its value...
            'credentials' => collect(array_keys(MailCredentials::SECRETS))
                ->mapWithKeys(fn (string $mailer) => [$mailer => MailCredentials::describe($settings, $mailer)])
                ->all(),
            'envMailer' => config('starter.env_mailer'),
        ]);
    }

    public function update(Request $request, UpdateMailSettings $action): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        $action->handle($actor, $request->all());

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
