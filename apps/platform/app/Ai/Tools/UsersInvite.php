<?php

namespace App\Ai\Tools;

use App\Actions\Invitations\InviteUser;
use App\Ai\Tools\Concerns\InteractsWithDirectory;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Gate;
use Starter\Ai\Agents\OperatorTool;
use Starter\Ai\Agents\ProposalPreview;
use Starter\Identity\Models\Invitation;

class UsersInvite extends OperatorTool
{
    use InteractsWithDirectory;

    public function description(): string
    {
        return 'Send an invitation email to the exact address the user gave you, with one role. Never invent recipients.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'email' => $schema->string()->description('Email address, exactly as given by the user.')->max(255)->required(),
            'role' => $schema->string()->description('Role granted when the invitation is accepted.')->required(),
        ];
    }

    protected function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:255'],
            'role' => ['required', 'string', 'max:100'],
        ];
    }

    protected function preview(array $arguments): ProposalPreview
    {
        Gate::forUser($this->actor())->authorize('create', Invitation::class);

        return new ProposalPreview(
            title: __('Invite :email as :role', ['email' => $arguments['email'], 'role' => $arguments['role']]),
            resourceType: 'invitation',
            resourceLabel: (string) $arguments['email'],
            changes: [
                ['field' => 'email', 'before' => null, 'after' => mb_strtolower((string) $arguments['email'])],
                ['field' => 'role', 'before' => null, 'after' => $arguments['role']],
            ],
            effects: [__('An invitation email is sent. It expires according to the invitation settings.')],
        );
    }

    protected function execute(array $arguments): array
    {
        $invitation = app(InviteUser::class)->handle($this->actor(), [
            'email' => mb_strtolower((string) $arguments['email']),
            'role' => $arguments['role'],
        ]);

        return [
            'message' => 'Invitation sent.',
            'invitation' => [
                'id' => $invitation->id,
                'email' => $invitation->email,
                'role' => $invitation->role,
                'expires_at' => $invitation->expires_at->toIso8601String(),
            ],
            'url' => route('admin.invitations.index', [], false),
        ];
    }
}
