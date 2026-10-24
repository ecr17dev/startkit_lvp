<?php

namespace App\Http\Controllers\Auth;

use App\Auth\LoginCodeBroker;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Fortify\Features;

class LoginCodeController extends Controller
{
    public function __construct(protected LoginCodeBroker $broker) {}

    /**
     * Send a login code to the given email address.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
            'remember' => ['nullable', 'boolean'],
        ]);

        $email = Str::lower($validated['email']);

        $this->broker->send($email, $request->ip(), $request->userAgent());

        $request->session()->put('login_code', [
            'email' => $email,
            'remember' => $request->boolean('remember'),
        ]);

        return to_route('login-code.show')->with('status', __('If an account exists for :email, we have sent a login code to it.', ['email' => $email]));
    }

    /**
     * Show the screen to enter the received code.
     */
    public function show(Request $request): Response|RedirectResponse
    {
        $pending = $request->session()->get('login_code');

        if (! is_array($pending) || empty($pending['email'])) {
            return to_route('login');
        }

        return Inertia::render('auth/LoginCode', [
            'email' => $pending['email'],
            'codeLength' => $this->broker->length(),
            'status' => $request->session()->get('status'),
        ]);
    }

    /**
     * Verify the code and log the user in.
     */
    public function verify(Request $request): RedirectResponse
    {
        $pending = $request->session()->get('login_code');

        if (! is_array($pending) || empty($pending['email'])) {
            return to_route('login');
        }

        $request->validate([
            'code' => ['required', 'string', 'digits:'.$this->broker->length()],
        ]);

        $user = $this->broker->verify($pending['email'], (string) $request->input('code'));

        if ($user === null) {
            throw ValidationException::withMessages([
                'code' => __('The code is invalid or has expired.'),
            ]);
        }

        $request->session()->forget('login_code');
        $remember = (bool) ($pending['remember'] ?? false);

        // Users with two-factor authentication still need to pass the challenge...
        if (Features::enabled(Features::twoFactorAuthentication()) && $user->hasEnabledTwoFactorAuthentication()) {
            $request->session()->put([
                'login.id' => $user->getKey(),
                'login.remember' => $remember,
            ]);

            return to_route('two-factor.login');
        }

        Auth::guard('web')->login($user, $remember);

        $request->session()->regenerate();

        return redirect()->intended(config('fortify.home'));
    }
}
