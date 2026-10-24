<?php

namespace App\Ai\Tools;

use App\Actions\Concerns\ChecksDelegation;
use App\Actions\Users\UpdateUser;
use App\Ai\Tools\Concerns\InteractsWithDirectory;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Starter\Ai\Agents\OperatorTool;
use Starter\Ai\Agents\ProposalPreview;
use Starter\Identity\Permissions;

class UsersRemoveRole extends OperatorTool
{
    use ChecksDelegation, InteractsWithDirectory;

    public function description(): string
    {
        return 'Remove a role from a user found with users_list. Requires the user\'s confirmation.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'user_id' => $schema->integer()->description('Id of the user.')->required(),
            'role' => $schema->string()->description('Role name to remove.')->required(),
        ];
    }

    protected function rules(): array
    {
        return [
            'user_id' => ['required', 'integer'],
            'role' => ['required', 'string', 'max:100'],
        ];
    }

    protected function preview(array $arguments): ProposalPreview
    {
        $user = $this->findUser((int) $arguments['user_id']);
        Gate::forUser($this->actor())->authorize('assignRoles', $user);

        $current = $user->getRoleNames()->all();
        $role = (string) $arguments['role'];

        if (! in_array($role, $current, true)) {
            throw ValidationException::withMessages(['role' => "The user does not have the role {$role}."]);
        }

        $this->ensureCanGrantRoles($this->actor(), [$role]);

        if ($role === Permissions::OWNER) {
            $this->ensureNotLastOwner($user, 'role', __('At least one active owner is required.'));
        }

        return new ProposalPreview(
            title: __('Remove the role :role from :name', ['role' => $role, 'name' => $user->name]),
            resourceType: 'user',
            resourceId: (string) $user->id,
            resourceLabel: "{$user->name} <{$user->email}>",
            resourceVersion: $this->userVersion($user),
            changes: [['field' => 'roles', 'before' => $current, 'after' => array_values(array_diff($current, [$role]))]],
            effects: [__('The user loses the permissions of this role on their next request.')],
            url: route('admin.users.edit', $user, false),
        );
    }

    protected function execute(array $arguments): array
    {
        $user = $this->findUser((int) $arguments['user_id']);
        $roles = array_values(array_diff($user->getRoleNames()->all(), [(string) $arguments['role']]));

        $user = app(UpdateUser::class)->handle($this->actor(), $user, ['roles' => $roles]);

        return ['message' => 'Role removed.', 'user' => $this->presentUser($user->load('roles'))];
    }
}
