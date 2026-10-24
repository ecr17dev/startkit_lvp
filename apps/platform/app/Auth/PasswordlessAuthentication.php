<?php

namespace App\Auth;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Starter\Identity\Identity;
use Starter\Identity\Models\Invitation;
use Starter\Identity\Models\LoginChallenge;
use Starter\Identity\Otp\OtpBroker;
use Starter\Identity\Otp\OtpPurpose;
use Starter\Identity\Permissions;

/**
 * One-time code flows: login, sign up, invitation acceptance and
 * confirmation of sensitive actions.
 *
 * Accounts are created only once the email is proven, inside a transaction,
 * so delivery retries never create duplicated users.
 */
class PasswordlessAuthentication
{
    /**
     * Session key holding the challenge of the current browser.
     */
    public const SESSION_KEY = 'auth_otp';

    public function __construct(protected OtpBroker $broker) {}

    /**
     * Request a login code. Unknown and disabled accounts receive a non
     * deliverable challenge: the response is the same for every email.
     */
    public function requestLogin(string $email, Request $request): LoginChallenge
    {
        $email = OtpBroker::normalizeEmail($email);
        $user = User::query()->where('email', $email)->first();

        return $this->broker->issue(OtpPurpose::Login, $email, [
            'user_id' => $user?->id,
            'deliverable' => $user !== null && $user->isActive(),
            ...$this->client($request),
        ]);
    }

    /**
     * Request a sign up code. Existing accounts receive a login code instead.
     */
    public function requestRegistration(string $name, string $email, Request $request): LoginChallenge
    {
        $email = OtpBroker::normalizeEmail($email);

        if (User::withTrashed()->where('email', $email)->exists()) {
            return $this->requestLogin($email, $request);
        }

        return $this->broker->issue(OtpPurpose::Register, $email, [
            'payload' => ['name' => trim($name)],
            ...$this->client($request),
        ]);
    }

    /**
     * Request the code that proves the invited email.
     */
    public function requestInvitation(Invitation $invitation, string $name, Request $request): LoginChallenge
    {
        return $this->broker->issue(OtpPurpose::Invitation, $invitation->email, [
            'invitation_id' => $invitation->id,
            'payload' => ['name' => trim($name)],
            ...$this->client($request),
        ]);
    }

    /**
     * Request a code to confirm a sensitive action of the authenticated user.
     */
    public function requestConfirmation(User $user, Request $request): LoginChallenge
    {
        return $this->broker->issue(OtpPurpose::Confirm, $user->email, [
            'user_id' => $user->id,
            ...$this->client($request),
        ]);
    }

    /**
     * Get the user a verified challenge authenticates, creating the account
     * for sign ups and invitations. Returns null when the account can no
     * longer be used (disabled, deleted, registration closed, revoked...).
     */
    public function complete(LoginChallenge $challenge): ?User
    {
        $user = match ($challenge->purpose) {
            OtpPurpose::Login, OtpPurpose::Confirm => User::query()->find($challenge->user_id),
            OtpPurpose::Register => $this->completeRegistration($challenge),
            OtpPurpose::Invitation => $this->completeInvitation($challenge),
        };

        if ($user === null || ! $user->isActive()) {
            return null;
        }

        // Receiving the code proves the ownership of the email address...
        if (! $user->hasVerifiedEmail()) {
            $user->markEmailAsVerified();
        }

        return $user;
    }

    /**
     * Get the challenge bound to the current session, if any.
     */
    public function pending(Request $request, ?OtpPurpose $purpose = null): ?LoginChallenge
    {
        $id = $request->session()->get(self::SESSION_KEY.'.id');
        $challenge = is_string($id) ? LoginChallenge::query()->find($id) : null;

        if ($challenge === null || ($purpose !== null && $challenge->purpose !== $purpose)) {
            return null;
        }

        return $challenge;
    }

    /**
     * Bind the challenge to the current session.
     *
     * @param  array<string, mixed>  $extra
     */
    public function remember(Request $request, LoginChallenge $challenge, array $extra = []): void
    {
        $request->session()->put(self::SESSION_KEY, [
            ...(array) $request->session()->get(self::SESSION_KEY, []),
            ...$extra,
            'id' => $challenge->id,
            'purpose' => $challenge->purpose->value,
        ]);
    }

    /**
     * Get an extra value stored with the session challenge.
     */
    public function sessionValue(Request $request, string $key, mixed $default = null): mixed
    {
        return $request->session()->get(self::SESSION_KEY.'.'.$key, $default);
    }

    public function forget(Request $request): void
    {
        $request->session()->forget(self::SESSION_KEY);
    }

    public function broker(): OtpBroker
    {
        return $this->broker;
    }

    protected function completeRegistration(LoginChallenge $challenge): ?User
    {
        if (! Identity::registrationMode()->allowsSignUp()) {
            return null;
        }

        return DB::transaction(function () use ($challenge) {
            $user = User::query()->createOrFirst(['email' => $challenge->email], [
                'name' => (string) $challenge->payload('name', ''),
            ]);

            if ($user->wasRecentlyCreated) {
                $user->assignRole($this->defaultRole());
                audit('auth.registered', $user, ['email' => $user->email], $user);
            }

            return $user;
        });
    }

    protected function completeInvitation(LoginChallenge $challenge): ?User
    {
        if (! Identity::registrationMode()->allowsInvitations()) {
            return null;
        }

        return DB::transaction(function () use ($challenge) {
            $invitation = Invitation::query()->whereKey($challenge->invitation_id)->lockForUpdate()->first();

            if ($invitation === null || ! $invitation->isPending()) {
                return null;
            }

            $user = User::query()->createOrFirst(['email' => $invitation->email], [
                'name' => (string) $challenge->payload('name', ''),
            ]);

            if (Role::query()->where('name', $invitation->role)->exists()) {
                $user->assignRole($invitation->role);
            }

            $invitation->forceFill(['accepted_at' => now(), 'accepted_user_id' => $user->id])->save();

            audit('invitations.accepted', $invitation, ['email' => $invitation->email, 'role' => $invitation->role], $user);

            return $user;
        });
    }

    /**
     * Get the role given to self registered users. Never a privileged one.
     */
    protected function defaultRole(): string
    {
        $role = settings('auth.default_role', Permissions::READER);

        if (! is_string($role) || in_array($role, [Permissions::OWNER, Permissions::ADMIN], true)
            || ! Role::query()->where('name', $role)->exists()) {
            return Permissions::READER;
        }

        return $role;
    }

    /**
     * @return array{ip_address: string|null, user_agent: string|null}
     */
    protected function client(Request $request): array
    {
        return ['ip_address' => $request->ip(), 'user_agent' => $request->userAgent()];
    }
}
