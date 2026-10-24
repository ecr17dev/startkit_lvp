<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Settings\Branding;
use App\Support\Translations;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        /** @var User|null $user */
        $user = $request->user();
        $locale = app()->getLocale();
        $branding = app(Branding::class)->toArray();

        return [
            ...parent::share($request),
            'name' => $branding['appName'],
            'branding' => $branding,
            'auth' => [
                'user' => $user ? [
                    ...$user->only(['id', 'name', 'email', 'email_verified_at', 'locale', 'created_at', 'updated_at']),
                    'roles' => $user->getRoleNames()->values()->all(),
                ] : null,
                'permissions' => $user?->permissionNames() ?? [],
            ],
            'features' => [
                'passwordLogin' => (bool) settings('auth.password_login', true),
                'codeLogin' => (bool) settings('auth.code_login', true),
                'registration' => (bool) settings('auth.registration', true),
            ],
            'locale' => $locale,
            'locales' => config('settings.locales'),
            'translations' => Inertia::once(fn () => Translations::for($locale))
                ->as('translations.'.Translations::version($locale)),
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
        ];
    }
}
