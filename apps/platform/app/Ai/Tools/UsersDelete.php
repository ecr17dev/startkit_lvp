<?php

namespace App\Ai\Tools;

use App\Actions\Concerns\ChecksDelegation;
use App\Actions\Users\DeleteUser;
use App\Ai\Tools\Concerns\InteractsWithDirectory;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Gate;
use Starter\Ai\Agents\OperatorTool;
use Starter\Ai\Agents\ProposalPreview;

class UsersDelete extends OperatorTool
{
    use ChecksDelegation, InteractsWithDirectory;

    public function description(): string
    {
        return 'Delete a user found with users_list: the account is anonymized and its access revoked; the audit trail is kept. Requires the user\'s confirmation.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'user_id' => $schema->integer()->description('Id of the user.')->required(),
        ];
    }

    protected function rules(): array
    {
        return ['user_id' => ['required', 'integer']];
    }

    protected function preview(array $arguments): ProposalPreview
    {
        $user = $this->findUser((int) $arguments['user_id']);
        Gate::forUser($this->actor())->authorize('delete', $user);
        $this->ensureNotLastOwner($user, 'user', __('The last active owner cannot be deleted.'));

        return new ProposalPreview(
            title: __('Delete :name', ['name' => $user->name]),
            resourceType: 'user',
            resourceId: (string) $user->id,
            resourceLabel: "{$user->name} <{$user->email}>",
            resourceVersion: $this->userVersion($user),
            changes: [
                ['field' => 'account', 'before' => $user->email, 'after' => __('deleted (anonymized)')],
                ['field' => 'roles', 'before' => $user->getRoleNames()->all(), 'after' => []],
            ],
            effects: [__('Sessions and API tokens are revoked.'), __('Personal data is anonymized; the audit trail is kept.'), __('This cannot be undone.')],
        );
    }

    protected function execute(array $arguments): array
    {
        $user = $this->findUser((int) $arguments['user_id']);
        $label = "{$user->name} <{$user->email}>";

        app(DeleteUser::class)->handle($this->actor(), $user);

        return ['message' => "User {$label} deleted.", 'id' => $user->id];
    }
}
