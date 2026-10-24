<?php

namespace App\Http\Controllers\Api\V1;

use App\Auth\PasswordlessAuthentication;
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
use Starter\Identity\Identity;
use Starter\Identity\Otp\OtpPurpose;

#[Group('Authentication', weight: 0)]
class AuthController extends Controller
{
    public function __construct(protected PasswordlessAuthentication $auth) {}

    /**
     * Request a one-time code.
     *
     * Always answers 202 with a `challenge_id`, whether the account exists
     * or not, so the endpoint cannot be used to discover accounts. Send the
     * challenge id with the code received by email to `/v1/auth/otp/verify`.
     *
     * @unauthenticated
     */
    public function requestOtp(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
        ]);

        $challenge = $this->auth->requestLogin($validated['email'], $request);

        return response()->json([
            /** Identifier of the challenge, required to verify the code. */
            'challenge_id' => $challenge->id,
            'code_length' => $this->auth->broker()->length(),
            'expires_in_minutes' => $this->auth->broker()->ttl(),
            /** Seconds before another code can be requested for this email. */
            'resend_available_in' => $challenge->resendAvailableIn(),
        ], 202);
    }

    /**
     * Exchange a one-time code for a token.
     *
     * Requires the `api.tokens.create` permission. Users with two-factor
     * authentication must also send `two_factor_code` or `recovery_code`.
     *
     * @unauthenticated
     */
    public function verifyOtp(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'challenge_id' => ['required', 'uuid'],
            'code' => ['required', 'string', 'digits:'.$this->auth->broker()->length()],
            /** A name to identify the device or integration using the token. */
            'device_name' => ['required', 'string', 'max:100'],
            /** Six digit code of the authenticator app (only when 2FA is enabled). */
            'two_factor_code' => ['nullable', 'string'],
            /** Recovery code (only when 2FA is enabled). */
            'recovery_code' => ['nullable', 'string'],
            'abilities' => ['array'],
            'abilities.*' => [Rule::in(ApiTokenController::ABILITIES)],
        ]);

        $result = $this->auth->broker()->verify($validated['challenge_id'], $validated['code'], OtpPurpose::Login);
        $user = $result->verified() && $result->challenge !== null ? $this->auth->complete($result->challenge) : null;

        if ($user === null) {
            throw ValidationException::withMessages(['code' => $result->status->message()]);
        }

        $this->ensureTwoFactorPassed($user, $validated['two_factor_code'] ?? null, $validated['recovery_code'] ?? null);

        return $this->tokenResponse($user, $validated['device_name'], $validated['abilities'] ?? null);
    }

    /**
     * Create a token with email and password.
     *
     * Only available when the deployment enables passwords
     * (STARTER_AUTH_METHOD=email-otp+password).
     *
     * @unauthenticated
     */
    public function token(Request $request): JsonResponse
    {
        abort_unless(Identity::passwordsEnabled(), 404);

        $validated = $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
            'device_name' => ['required', 'string', 'max:100'],
            'two_factor_code' => ['nullable', 'string'],
            'recovery_code' => ['nullable', 'string'],
            'abilities' => ['array'],
            'abilities.*' => [Rule::in(ApiTokenController::ABILITIES)],
        ]);

        $user = User::query()->where('email', Str::lower($validated['email']))->first();

        if ($user === null || ! Hash::check($validated['password'], (string) $user->password) || ! $user->isActive()) {
            throw ValidationException::withMessages(['email' => __('auth.failed')]);
        }

        $this->ensureTwoFactorPassed($user, $validated['two_factor_code'] ?? null, $validated['recovery_code'] ?? null);

        return $this->tokenResponse($user, $validated['device_name'], $validated['abilities'] ?? null);
    }

    /**
     * Get the authenticated user.
     */
    public function me(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json([
            'data' => (new UserResource($user->load('roles')))->resolve($request),
            /** @var list<string> */
            'permissions' => $user->permissionNames(),
        ]);
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
        abort_unless($user->can('api.tokens.create'), 403, __('Your account is not allowed to create API tokens.'));

        $token = $user->createToken($deviceName, $abilities ?: ApiTokenController::ABILITIES);

        audit('api_tokens.created', $user, ['name' => $deviceName, 'abilities' => $token->accessToken->abilities], $user);

        return response()->json([
            /** The plain text token. Send it as `Authorization: Bearer <token>`. */
            'token' => $token->plainTextToken,
            'token_type' => 'Bearer',
            /** @var list<string> */
            'abilities' => $token->accessToken->abilities,
            'user' => new UserResource($user->load('roles')),
        ], 201);
    }
}
