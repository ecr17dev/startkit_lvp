<?php

namespace App\Http\Controllers\Auth;

use App\Auth\PasswordlessAuthentication;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Starter\Identity\Otp\OtpPurpose;

/**
 * Confirm sensitive actions with a one-time code, the passwordless
 * equivalent of Laravel's password confirmation (password.confirm).
 */
class ConfirmWithCodeController extends Controller
{
    public function __construct(protected PasswordlessAuthentication $auth) {}

    /**
     * Send a confirmation code to the authenticated user.
     */
    public function store(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $pending = $this->auth->pending($request, OtpPurpose::Confirm);

        if ($pending !== null && $pending->isActive() && $pending->resendAvailableIn() > 0) {
            throw ValidationException::withMessages([
                'code' => __('Please wait :seconds seconds before requesting a new code.', ['seconds' => $pending->resendAvailableIn()]),
            ]);
        }

        $this->auth->remember($request, $this->auth->requestConfirmation($user, $request));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('We sent a code to :email.', ['email' => $user->email])]);

        return back();
    }

    /**
     * Verify the code and mark the session as confirmed.
     */
    public function verify(Request $request): RedirectResponse
    {
        $request->validate([
            'code' => ['required', 'string', 'digits:'.$this->auth->broker()->length()],
        ]);

        $challenge = $this->auth->pending($request, OtpPurpose::Confirm);

        if ($challenge === null || $challenge->user_id !== $request->user()?->getAuthIdentifier()) {
            throw ValidationException::withMessages(['code' => __('Request a new code.')]);
        }

        $result = $this->auth->broker()->verify($challenge->id, (string) $request->input('code'), OtpPurpose::Confirm);

        if (! $result->verified()) {
            throw ValidationException::withMessages(['code' => $result->status->message()]);
        }

        $this->auth->forget($request);
        $request->session()->passwordConfirmed();

        return redirect()->intended(config('fortify.home'));
    }
}
