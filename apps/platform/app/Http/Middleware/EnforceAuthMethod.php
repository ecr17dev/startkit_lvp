<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Starter\Identity\Identity;
use Symfony\Component\HttpFoundation\Response;

/**
 * Disable the password based routes registered by Fortify unless the
 * deployment allows them (STARTER_AUTH_METHOD=email-otp+password).
 */
class EnforceAuthMethod
{
    /**
     * @var list<string>
     */
    protected array $passwordRoutes = [
        'login.store',
        'password.request',
        'password.email',
        'password.reset',
        'password.update',
        'user-password.update',
        'password.confirm.store',
    ];

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! Identity::passwordsEnabled() && $request->routeIs(...$this->passwordRoutes)) {
            abort(404);
        }

        return $next($request);
    }
}
