<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Send users to the onboarding until their profile is complete and, for
 * owners, until the initial workspace setup is done.
 */
class EnsureOnboardingCompleted
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User && ! $request->expectsJson() && self::needsOnboarding($user)) {
            return redirect()->route('onboarding.show');
        }

        return $next($request);
    }

    public static function needsOnboarding(User $user): bool
    {
        return blank($user->name) || ($user->isOwner() && ! settings('onboarding.completed', false));
    }
}
