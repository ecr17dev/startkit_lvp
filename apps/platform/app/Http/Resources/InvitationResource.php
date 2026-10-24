<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Starter\Identity\Models\Invitation;

/**
 * @mixin Invitation
 */
class InvitationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'email' => $this->email,
            'role' => $this->role,
            /** @var 'pending'|'accepted'|'revoked'|'expired' */
            'status' => $this->status(),
            'invited_by' => $this->whenLoaded('inviter', fn () => $this->inviter?->getAttribute('name')),
            'expires_at' => $this->expires_at,
            'accepted_at' => $this->accepted_at,
            'revoked_at' => $this->revoked_at,
            'last_sent_at' => $this->last_sent_at,
            'sends' => $this->sends,
            'created_at' => $this->created_at,
        ];
    }
}
