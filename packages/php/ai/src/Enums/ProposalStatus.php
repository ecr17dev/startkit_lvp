<?php

namespace Starter\Ai\Enums;

enum ProposalStatus: string
{
    // Waiting for the user's decision...
    case Pending = 'pending';

    // Confirmed by the user, about to run (single use)...
    case Approved = 'approved';
    case Executing = 'executing';
    case Executed = 'executed';
    case Failed = 'failed';

    // Not executed...
    case Rejected = 'rejected';
    case Expired = 'expired';
    case Superseded = 'superseded';

    public function isFinal(): bool
    {
        return ! in_array($this, [self::Pending, self::Approved, self::Executing], true);
    }
}
