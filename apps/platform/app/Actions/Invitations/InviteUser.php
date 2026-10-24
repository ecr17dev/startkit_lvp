<?php

namespace App\Actions\Invitations;

use App\Actions\Concerns\ChecksDelegation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Starter\Identity\Identity;
use Starter\Identity\Models\Invitation;
use Starter\Identity\Notifications\InvitationNotification;

/**
 * Invite someone by email with a role the inviter is allowed to grant.
 */
class InviteUser
{
    use ChecksDelegation;

    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', Rule::unique(User::class, 'email')],
            'role' => ['required', 'string', Rule::exists('roles', 'name')],
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function handle(User $actor, array $input): Invitation
    {
        Gate::forUser($actor)->authorize('create', Invitation::class);

        if (! Identity::registrationMode()->allowsInvitations()) {
            throw ValidationException::withMessages([
                'email' => __('Invitations are disabled: registration is closed.'),
            ]);
        }

        if (is_string($input['email'] ?? null)) {
            $input['email'] = mb_strtolower(trim($input['email']));
        }

        /** @var array{email: string, role: string} $data */
        $data = Validator::make($input, self::rules(), [
            'email.unique' => __('A user with this email already exists.'),
        ])->validate();

        $this->ensureCanGrantRoles($actor, [$data['role']], 'role');

        [$invitation, $token] = DB::transaction(function () use ($actor, $data) {
            // A new invitation replaces the pending ones of the same email...
            Invitation::query()->pending()->where('email', $data['email'])->update(['revoked_at' => now()]);

            $invitation = new Invitation([
                'email' => $data['email'],
                'role' => $data['role'],
                'invited_by' => $actor->id,
                'expires_at' => now()->addDays(max(1, (int) config('starter.auth.invitation_ttl_days', 7))),
            ]);
            $token = $invitation->refreshToken();
            $invitation->save();

            audit('invitations.created', $invitation, ['email' => $invitation->email, 'role' => $invitation->role], $actor);

            return [$invitation, $token];
        });

        self::send($invitation, $token, $actor);

        return $invitation;
    }

    /**
     * Email the invitation link.
     */
    public static function send(Invitation $invitation, string $token, ?User $inviter = null): void
    {
        Notification::route('mail', $invitation->email)->notifyNow(
            new InvitationNotification($invitation, route('invitations.show', $token), $inviter?->name),
        );

        $invitation->forceFill(['last_sent_at' => now(), 'sends' => $invitation->sends + 1])->save();
    }
}
