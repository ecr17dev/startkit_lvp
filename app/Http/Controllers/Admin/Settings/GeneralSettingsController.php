<?php

namespace App\Http\Controllers\Admin\Settings;

use App\Http\Controllers\Controller;
use App\Settings\SettingsManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class GeneralSettingsController extends Controller
{
    public function __construct(protected SettingsManager $settings) {}

    public function edit(): Response
    {
        return Inertia::render('admin/settings/General', [
            'settings' => $this->settings->group('general'),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'locale' => ['required', 'string', Rule::in(array_keys((array) config('settings.locales')))],
            'support_email' => ['nullable', 'email', 'max:255'],
        ]);

        $this->settings->setGroup('general', $validated);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Settings saved.')]);

        return back();
    }
}
