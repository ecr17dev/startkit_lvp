<?php

namespace Starter\Identity\Registration;

/**
 * Who can create an account.
 *
 * - open: anyone can sign up with their email (invitations also work).
 * - invite-only: accounts are only created from invitations.
 * - closed: no sign ups nor invitations; administrators create the users.
 */
enum RegistrationMode: string
{
    case Open = 'open';
    case InviteOnly = 'invite-only';
    case Closed = 'closed';

    public function allowsSignUp(): bool
    {
        return $this === self::Open;
    }

    public function allowsInvitations(): bool
    {
        return $this !== self::Closed;
    }
}
