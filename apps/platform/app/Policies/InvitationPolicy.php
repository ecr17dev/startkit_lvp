<?php

namespace App\Policies;

use App\Models\User;
use Starter\Identity\Models\Invitation;

class InvitationPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->can('users.invite') || $actor->can('users.read');
    }

    public function create(User $actor): bool
    {
        return $actor->can('users.invite');
    }

    /**
     * Resend the invitation (also after it expired).
     */
    public function update(User $actor, Invitation $invitation): bool
    {
        return $actor->can('users.invite') && $invitation->accepted_at === null && $invitation->revoked_at === null;
    }

    public function delete(User $actor, Invitation $invitation): bool
    {
        return $actor->can('users.invite') && $invitation->accepted_at === null;
    }
}
