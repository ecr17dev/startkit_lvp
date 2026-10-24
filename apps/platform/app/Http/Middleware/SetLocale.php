<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    /**
     * Resolve the locale from the user preference, the session, or the default setting.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $available = array_keys((array) config('starter.locales'));

        $locale = collect([
            $request->user()?->locale,
            $request->hasSession() ? $request->session()->get('locale') : null,
            config('app.locale'),
        ])->first(fn ($locale) => is_string($locale) && in_array($locale, $available, true));

        if (is_string($locale)) {
            app()->setLocale($locale);
            Carbon::setLocale($locale);
        }

        return $next($request);
    }
}
