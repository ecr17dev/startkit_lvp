<?php

namespace App\Http\Controllers\Api\V1;

use App\Auth\LoginCodeBroker;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Settings\ApiTokenController;
use App\Http\Resources\UserResource;
use App\Models\User;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use Laravel\Fortify\Features;

#[Group('Authentication', weight: 0)]
class AuthController extends Controller
{
    public function __construct(protected LoginCodeBroker $broker) {}

    /**
     * Create a token with email and password.
     *
     * Users with two-factor authentication enabled must also send a valid
     * `two_factor_code` (TOTP) or one of their `recovery_code`.
     *
     * @unauthenticated
     */
    public function token(Request $request): JsonResponse
    {
        abort_unless(settings('auth.password_login', true), 404);

        $validated = $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
            /** A name to identify the device or integration using the token. */
            'device_name' => ['required', 'string', 'max:100'],
            /** Six digit code of the authenticator app (only when 2FA is enabled). */
            'two_factor_code' => ['nullable', 'string'],
            /** Recovery code (only when 2FA is enabled). */
            'recovery_code' => ['nullable', 'string'],
            'abilities' => ['array'],
            'abilities.*' => [Rule::in(ApiTokenController::ABILITIES)],
        ]);

        $user = User::query()->where('email', Str::lower($validated['email']))->first();

        if ($user === null || ! Hash::check($validated['password'], $user->password)) {
            throw ValidationException::withMessages(['email' => __('auth.failed')]);
        }

        $this->ensureCanLogin($user);
        $this->ensureTwoFactorPassed($user, $validated['two_factor_code'] ?? null, $validated['recovery_code'] ?? null);

        return $this->tokenResponse($user, $validated['device_name'], $validated['abilities'] ?? null);
    }

    /**
     * Send a login code by email.
     *
     * Always answers with the same message, whether the account exists or not.
     *
     * @unauthenticated
     */
    public function requestCode(Request $request): JsonResponse
    {
        abort_unless(settings('auth.code_login', true), 404);

        $validated = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
        ]);

        $this->broker->send($validated['email'], $request->ip(), $request->userAgent());

        return response()->json([
            'message' => __('If an account exists for :email, we have sent a login code to it.', ['email' => $validated['email']]),
            'code_length' => $this->broker->length(),
            'expires_in_minutes' => $this->broker->ttl(),
        ]);
    }

    /**
     * Exchange a login code for a token.
     *
     * @unauthenticated
     */
    public function verifyCode(Request $request): JsonResponse
    {
        abort_unless(settings('auth.code_login', true), 404);

        $validated = $request->validate([
            'email' => ['required', 'string', 'email'],
            'code' => ['required', 'string', 'digits:'.$this->broker->length()],
            'device_name' => ['required', 'string', 'max:100'],
            'two_factor_code' => ['nullable', 'string'],
            'recovery_code' => ['nullable', 'string'],
            'abilities' => ['array'],
            'abilities.*' => [Rule::in(ApiTokenController::ABILITIES)],
        ]);

        $user = $this->broker->verify($validated['email'], $validated['code']);

        if ($user === null) {
            throw ValidationException::withMessages(['code' => __('The code is invalid or has expired.')]);
        }

        $this->ensureTwoFactorPassed($user, $validated['two_factor_code'] ?? null, $validated['recovery_code'] ?? null);

        return $this->tokenResponse($user, $validated['device_name'], $validated['abilities'] ?? null);
    }

    /**
     * Get the authenticated user.
     */
    public function me(Request $request): UserResource
    {
        /** @var User $user */
        $user = $request->user();

        return new UserResource($user->load('roles'));
    }

    /**
     * Revoke the token used for the request.
     */
    public function logout(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $user->currentAccessToken()->delete();

        return response()->json(['message' => __('Token revoked.')]);
    }

    protected function ensureCanLogin(User $user): void
    {
        if (! $user->is_active) {
            throw ValidationException::withMessages(['email' => __('Your account has been deactivated.')]);
        }
    }

    protected function ensureTwoFactorPassed(User $user, ?string $code, ?string $recoveryCode): void
    {
        if (! Features::enabled(Features::twoFactorAuthentication()) || ! $user->hasEnabledTwoFactorAuthentication()) {
            return;
        }

        if (filled($code) && app(TwoFactorAuthenticationProvider::class)->verify(decrypt((string) $user->two_factor_secret), (string) $code)) {
            return;
        }

        if (filled($recoveryCode)) {
            $valid = collect($user->recoveryCodes())->first(fn (string $stored) => hash_equals($stored, (string) $recoveryCode));

            if ($valid !== null) {
                $user->replaceRecoveryCode($valid);

                return;
            }
        }

        throw ValidationException::withMessages([
            'two_factor_code' => __('A valid two-factor authentication code is required.'),
        ]);
    }

    /**
     * @param  list<string>|null  $abilities
     */
    protected function tokenResponse(User $user, string $deviceName, ?array $abilities): JsonResponse
    {
        $token = $user->createToken($deviceName, $abilities ?: ApiTokenController::ABILITIES);

        return response()->json([
            /** The plain text token. Send it as `Authorization: Bearer <token>`. */
            'token' => $token->plainTextToken,
            'token_type' => 'Bearer',
            'abilities' => $token->accessToken->abilities,
            'user' => new UserResource($user->load('roles')),
        ], 201);
    }
}
