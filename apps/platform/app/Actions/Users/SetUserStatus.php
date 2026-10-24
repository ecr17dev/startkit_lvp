<?php

namespace App\Actions\Users;

use App\Actions\Concerns\ChecksDelegation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Disable or enable a user. Disabling revokes every session and API token.
 */
class SetUserStatus
{
    use ChecksDelegation;

    public function __construct(protected RevokeSessions $revokeSessions) {}

    public function handle(User $actor, User $user, bool $active): User
    {
        Gate::forUser($actor)->authorize('disable', $user);

        if ($user->is_active === $active) {
            return $user;
        }

        if (! $active) {
            $this->ensureNotLastOwner($user, 'user', __('The last active owner cannot be disabled.'));
        }

        return DB::transaction(function () use ($actor, $user, $active) {
            $user->forceFill([
                'is_active' => $active,
                'disabled_at' => $active ? null : now(),
            ])->save();

            if (! $active) {
                $this->revokeSessions->revokeAll($user);
            }

            audit($active ? 'users.enabled' : 'users.disabled', $user, [], $actor);

            return $user;
        });
    }
}
