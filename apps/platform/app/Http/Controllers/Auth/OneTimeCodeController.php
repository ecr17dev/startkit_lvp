<?php

namespace App\Http\Controllers\Auth;

use App\Auth\PasswordlessAuthentication;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Fortify\Features;
use Starter\Identity\Otp\OtpPurpose;

/**
 * Passwordless login: the user enters an email, receives a one-time code
 * and enters it to log in. Also completes sign ups and invitations.
 */
class OneTimeCodeController extends Controller
{
    public function __construct(protected PasswordlessAuthentication $auth) {}

    /**
     * Send a login code to the email address.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
            'remember' => ['nullable', 'boolean'],
        ]);

        $challenge = $this->auth->requestLogin($validated['email'], $request);
        $this->auth->remember($request, $challenge, ['remember' => $request->boolean('remember')]);

        return to_route('login.verify');
    }

    /**
     * Show the screen to enter the code.
     */
    public function show(Request $request): Response|RedirectResponse
    {
        $challenge = $this->auth->pending($request);

        if ($challenge === null || $challenge->purpose === OtpPurpose::Confirm || $challenge->consumed_at !== null) {
            return to_route('login');
        }

        return Inertia::render('auth/VerifyCode', [
            'email' => $challenge->email,
            'purpose' => $challenge->purpose->value,
            'codeLength' => $this->auth->broker()->length(),
            'expiresInMinutes' => $this->auth->broker()->ttl(),
            'resendAvailableIn' => $challenge->resendAvailableIn(),
        ]);
    }

    /**
     * Verify the code and log the user in.
     */
    public function verify(Request $request): RedirectResponse
    {
        $challenge = $this->auth->pending($request);

        if ($challenge === null || $challenge->purpose === OtpPurpose::Confirm) {
            return to_route('login');
        }

        $request->validate([
            'code' => ['required', 'string', 'digits:'.$this->auth->broker()->length()],
        ]);

        $result = $this->auth->broker()->verify($challenge->id, (string) $request->input('code'), $challenge->purpose);
        $user = $result->verified() && $result->challenge !== null ? $this->auth->complete($result->challenge) : null;

        if ($user === null) {
            throw ValidationException::withMessages(['code' => $result->status->message()]);
        }

        $remember = (bool) $this->auth->sessionValue($request, 'remember', false);
        $this->auth->forget($request);

        return $this->login($request, $user, $remember);
    }

    /**
     * Send a new code once the cooldown has passed.
     */
    public function resend(Request $request): RedirectResponse
    {
        $challenge = $this->auth->pending($request);

        if ($challenge === null || $challenge->purpose === OtpPurpose::Confirm) {
            return to_route('login');
        }

        if (($seconds = $challenge->resendAvailableIn()) > 0) {
            throw ValidationException::withMessages([
                'code' => __('Please wait :seconds seconds before requesting a new code.', ['seconds' => $seconds]),
            ]);
        }

        $this->auth->remember($request, $this->auth->broker()->resend($challenge));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('We sent you a new code.')]);

        return to_route('login.verify');
    }

    /**
     * Log the user in, passing through the two-factor challenge when enabled.
     */
    protected function login(Request $request, User $user, bool $remember): RedirectResponse
    {
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
