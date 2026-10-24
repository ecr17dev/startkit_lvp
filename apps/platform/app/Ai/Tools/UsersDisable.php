<?php

namespace App\Ai\Tools;

use App\Actions\Concerns\ChecksDelegation;
use App\Actions\Users\SetUserStatus;
use App\Ai\Tools\Concerns\InteractsWithDirectory;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Gate;
use Starter\Ai\Agents\OperatorTool;
use Starter\Ai\Agents\ProposalPreview;

class UsersDisable extends OperatorTool
{
    use ChecksDelegation, InteractsWithDirectory;

    public function description(): string
    {
        return 'Disable (or enable again) the access of a user found with users_list. Disabling closes their sessions and tokens. Requires the user\'s confirmation.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'user_id' => $schema->integer()->description('Id of the user.')->required(),
            'active' => $schema->boolean()->description('false disables the account (default), true enables it again.'),
        ];
    }

    protected function rules(): array
    {
        return [
            'user_id' => ['required', 'integer'],
            'active' => ['sometimes', 'boolean'],
        ];
    }

    protected function preview(array $arguments): ProposalPreview
    {
        $user = $this->findUser((int) $arguments['user_id']);
        $active = (bool) ($arguments['active'] ?? false);
        Gate::forUser($this->actor())->authorize('disable', $user);

        if (! $active) {
            $this->ensureNotLastOwner($user, 'user', __('The last active owner cannot be disabled.'));
        }

        return new ProposalPreview(
            title: $active ? __('Enable :name', ['name' => $user->name]) : __('Disable :name', ['name' => $user->name]),
            resourceType: 'user',
            resourceId: (string) $user->id,
            resourceLabel: "{$user->name} <{$user->email}>",
            resourceVersion: $this->userVersion($user),
            changes: [['field' => 'status', 'before' => $user->is_active ? __('Active') : __('Inactive'), 'after' => $active ? __('Active') : __('Inactive')]],
            effects: $active ? [__('The person can sign in again.')] : [__('The person cannot sign in.'), __('Their sessions and API tokens are revoked.')],
            url: route('admin.users.edit', $user, false),
        );
    }

    protected function execute(array $arguments): array
    {
        $user = app(SetUserStatus::class)->handle($this->actor(), $this->findUser((int) $arguments['user_id']), (bool) ($arguments['active'] ?? false));

        return ['message' => $user->is_active ? 'User enabled.' : 'User disabled.', 'user' => $this->presentUser($user->load('roles'))];
    }
}
