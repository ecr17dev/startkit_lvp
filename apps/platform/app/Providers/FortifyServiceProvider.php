<?php

namespace App\Providers;

use App\Actions\Fortify\ResetUserPassword;
use App\Auth\PasswordlessAuthentication;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Laravel\Fortify\Features;
use Laravel\Fortify\Fortify;
use Starter\Identity\Identity;
use Starter\Identity\Otp\OtpBroker;
use Starter\Identity\Otp\OtpPurpose;

class FortifyServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureActions();
        $this->configureAuthentication();
        $this->configureViews();
        $this->configureRateLimiting();
    }

    /**
     * Configure Fortify actions. Registration is not handled by Fortify: the
     * starter signs users up with one-time codes (RegisterController).
     */
    private function configureActions(): void
    {
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);
    }

    /**
     * Password login (only with STARTER_AUTH_METHOD=email-otp+password, see
     * EnforceAuthMethod) is restricted to active accounts.
     */
    private function configureAuthentication(): void
    {
        Fortify::authenticateUsing(function (Request $request): ?User {
            $user = User::query()->where('email', Str::lower((string) $request->input(Fortify::username())))->first();

            if ($user === null || ! Hash::check((string) $request->input('password'), (string) $user->password)) {
                return null;
            }

            if (! $user->isActive()) {
                throw ValidationException::withMessages([
                    Fortify::username() => __('Your account has been deactivated.'),
                ]);
            }

            return $user;
        });
    }

    /**
     * Configure Fortify views.
     */
    private function configureViews(): void
    {
        Fortify::loginView(fn (Request $request) => Inertia::render('auth/Login', [
            'canResetPassword' => Identity::passwordsEnabled() && Features::enabled(Features::resetPasswords()),
            'status' => $request->session()->get('status'),
            'codeLength' => app(OtpBroker::class)->length(),
        ]));

        Fortify::resetPasswordView(fn (Request $request) => Inertia::render('auth/ResetPassword', [
            'email' => $request->email,
            'token' => $request->route('token'),
            'passwordRules' => Password::defaults()->toPasswordRulesString(),
        ]));

        Fortify::requestPasswordResetLinkView(fn (Request $request) => Inertia::render('auth/ForgotPassword', [
            'status' => $request->session()->get('status'),
        ]));

        Fortify::verifyEmailView(fn (Request $request) => Inertia::render('auth/VerifyEmail', [
            'status' => $request->session()->get('status'),
        ]));

        Fortify::twoFactorChallengeView(fn () => Inertia::render('auth/TwoFactorChallenge'));

        // Passwordless accounts confirm sensitive actions with a one-time code
        // (ConfirmWithCodeController) or a passkey...
        Fortify::confirmPasswordView(function (Request $request) {
            $user = $request->user();
            $broker = app(OtpBroker::class);
            $pending = app(PasswordlessAuthentication::class)->pending($request, OtpPurpose::Confirm);

            return Inertia::render('auth/ConfirmPassword', [
                'canUsePassword' => Identity::passwordsEnabled() && $user instanceof User && $user->hasPassword(),
                'codeSent' => $pending !== null && $pending->isActive(),
                'resendAvailableIn' => $pending?->resendAvailableIn() ?? 0,
                'codeLength' => $broker->length(),
                'email' => $user?->getAttribute('email'),
            ]);
        });
    }

    /**
     * Configure rate limiting.
     */
    private function configureRateLimiting(): void
    {
        RateLimiter::for('two-factor', function (Request $request) {
            return Limit::perMinute(5)->by($request->session()->get('login.id'));
        });

        RateLimiter::for('login', function (Request $request) {
            $throttleKey = Str::transliterate(Str::lower($request->input(Fortify::username())).'|'.$request->ip());

            return Limit::perMinute(5)->by($throttleKey);
        });

        RateLimiter::for('passkeys', function (Request $request) {
            return Limit::perMinute(10)->by(
                ($request->input('credential.id') ?: $request->session()->getId()).'|'.$request->ip(),
            );
        });
    }
}
