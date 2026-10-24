<?php

namespace App\Http\Controllers;

use App\Http\Middleware\EnsureOnboardingCompleted;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Starter\Identity\Identity;
use Starter\Identity\Registration\RegistrationMode;

/**
 * First steps after the first login: complete the profile and, for the
 * owner, the essential workspace settings.
 */
class OnboardingController extends Controller
{
    public function show(Request $request): Response|RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        if (! EnsureOnboardingCompleted::needsOnboarding($user)) {
            return redirect()->route('dashboard');
        }

        $workspace = $user->isOwner() && ! settings('onboarding.completed', false);

        return Inertia::render('onboarding/Index', [
            'profile' => ['name' => $user->name, 'locale' => $user->locale ?? app()->getLocale()],
            'workspace' => $workspace ? [
                'app_name' => settings('branding.app_name'),
                'locale' => settings('general.locale'),
                'timezone' => settings('general.timezone'),
                'registration' => Identity::registrationMode()->value,
            ] : null,
            'timezones' => $workspace ? timezone_identifiers_list() : [],
            'registrationModes' => array_map(fn (RegistrationMode $mode) => $mode->value, RegistrationMode::cases()),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $workspace = $user->isOwner() && ! settings('onboarding.completed', false);
        $locales = array_keys((array) config('starter.locales'));

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'locale' => ['required', Rule::in($locales)],
            'app_name' => [Rule::requiredIf($workspace), 'nullable', 'string', 'max:100'],
            'workspace_locale' => [Rule::requiredIf($workspace), 'nullable', Rule::in($locales)],
            'timezone' => [Rule::requiredIf($workspace), 'nullable', 'timezone:all'],
            'registration' => [Rule::requiredIf($workspace), 'nullable', Rule::enum(RegistrationMode::class)],
        ]);

        $user->forceFill(['name' => $validated['name'], 'locale' => $validated['locale']])->save();

        if ($workspace) {
            settings()->set([
                'branding.app_name' => $validated['app_name'],
                'general.locale' => $validated['workspace_locale'],
                'general.timezone' => $validated['timezone'],
                'auth.registration' => $validated['registration'],
                'onboarding.completed' => true,
            ]);

            audit('onboarding.completed', null, [
                'app_name' => $validated['app_name'],
                'registration' => $validated['registration'],
            ]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('All set. Welcome!')]);

        return redirect()->route('dashboard');
    }
}
