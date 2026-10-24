<?php

namespace App\Actions\Invitations;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Starter\Identity\Models\Invitation;

class RevokeInvitation
{
    public function handle(User $actor, Invitation $invitation): Invitation
    {
        Gate::forUser($actor)->authorize('delete', $invitation);

        if ($invitation->revoked_at === null) {
            $invitation->forceFill(['revoked_at' => now()])->save();

            audit('invitations.revoked', $invitation, ['email' => $invitation->email], $actor);
        }

        return $invitation;
    }
}
