<?php

namespace App\Actions\Users;

use App\Actions\Concerns\ChecksDelegation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * Delete a user: personal data is anonymized and the row is soft deleted
 * so the audit trail and the AI ledger keep consistent references.
 */
class DeleteUser
{
    use ChecksDelegation;

    public function __construct(protected RevokeSessions $revokeSessions) {}

    public function handle(User $actor, User $user): void
    {
        Gate::forUser($actor)->authorize('delete', $user);

        $this->ensureNotLastOwner($user, 'user', __('The last active owner cannot be deleted.'));

        DB::transaction(function () use ($actor, $user) {
            $emailHash = $this->anonymize($user);

            audit('users.deleted', $user, ['email_sha256' => $emailHash], $actor);
        });
    }

    /**
     * Anonymize and soft delete the account. Returns the hash of the old email.
     */
    public function anonymize(User $user): string
    {
        $emailHash = hash('sha256', $user->email);

        $this->revokeSessions->revokeAll($user);
        $user->syncRoles([]);
        $user->passkeys()->delete();

        $user->forceFill([
            'name' => __('Deleted user'),
            'email' => 'deleted-'.$user->id.'-'.Str::lower(Str::random(8)).'@users.invalid',
            'password' => null,
            'locale' => null,
            'is_active' => false,
            'disabled_at' => now(),
            'remember_token' => null,
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
            'last_login_ip' => null,
        ])->save();

        $user->delete();

        return $emailHash;
    }
}
