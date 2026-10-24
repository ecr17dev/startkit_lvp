<?php

namespace App\Actions\Invitations;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Starter\Identity\Models\Invitation;

/**
 * Send the invitation again with a new link and a renewed expiration.
 */
class ResendInvitation
{
    public function handle(User $actor, Invitation $invitation): Invitation
    {
        Gate::forUser($actor)->authorize('update', $invitation);

        if ($invitation->accepted_at !== null || $invitation->revoked_at !== null) {
            throw ValidationException::withMessages(['invitation' => __('This invitation can no longer be sent.')]);
        }

        if ($invitation->last_sent_at !== null && $invitation->last_sent_at->gt(now()->subMinute())) {
            throw ValidationException::withMessages(['invitation' => __('Please wait a minute before sending it again.')]);
        }

        $token = $invitation->refreshToken();
        $invitation->expires_at = now()->addDays(max(1, (int) config('starter.auth.invitation_ttl_days', 7)));
        $invitation->save();

        InviteUser::send($invitation, $token, $actor);

        audit('invitations.resent', $invitation, ['email' => $invitation->email], $actor);

        return $invitation;
    }
}
