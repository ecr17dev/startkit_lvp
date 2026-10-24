<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnforceAuthSettings
{
    /**
     * Route names that are disabled when the related setting is turned off.
     *
     * @var array<string, list<string>>
     */
    protected array $routes = [
        'auth.registration' => ['register', 'register.store'],
        'auth.password_login' => ['login.store', 'password.request', 'password.email', 'password.reset', 'password.update'],
        'auth.code_login' => ['login-code.store', 'login-code.show', 'login-code.verify'],
    ];

    /**
     * Block the authentication routes disabled from the admin panel.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        foreach ($this->routes as $setting => $names) {
            if ($request->routeIs(...$names) && ! settings($setting, true)) {
                abort(404);
            }
        }

        return $next($request);
    }
}
