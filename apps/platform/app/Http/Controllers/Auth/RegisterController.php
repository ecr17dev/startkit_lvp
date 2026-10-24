<?php

namespace App\Http\Controllers\Auth;

use App\Auth\PasswordlessAuthentication;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Starter\Identity\Identity;

/**
 * Self sign up (registration mode "open"). The account is created after
 * the email is verified with a one-time code.
 */
class RegisterController extends Controller
{
    public function __construct(protected PasswordlessAuthentication $auth) {}

    public function create(): Response
    {
        abort_unless(Identity::registrationMode()->allowsSignUp(), 404);

        return Inertia::render('auth/Register', [
            'codeLength' => $this->auth->broker()->length(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless(Identity::registrationMode()->allowsSignUp(), 404);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255'],
        ]);

        $challenge = $this->auth->requestRegistration($validated['name'], $validated['email'], $request);
        $this->auth->remember($request, $challenge, ['remember' => false]);

        return to_route('login.verify');
    }
}
