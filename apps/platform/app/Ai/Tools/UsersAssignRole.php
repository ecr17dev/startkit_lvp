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

class UsersAssignRole extends OperatorTool
{
    use ChecksDelegation, InteractsWithDirectory;

    public function description(): string
    {
        return 'Give a role to a user found with users_list. You can only grant roles whose permissions you hold. Requires the user\'s confirmation.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'user_id' => $schema->integer()->description('Id of the user.')->required(),
            'role' => $schema->string()->description('Role name (see roles_list).')->required(),
            'replace' => $schema->boolean()->description('true replaces every current role by this one (default false adds it).'),
        ];
    }

    protected function rules(): array
    {
        return [
            'user_id' => ['required', 'integer'],
            'role' => ['required', 'string', 'max:100'],
            'replace' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @param  array<int, string>  $current
     * @return list<string>
     */
    protected function targetRoles(array $arguments, array $current): array
    {
        return ($arguments['replace'] ?? false)
            ? [(string) $arguments['role']]
            : array_values(array_unique([...$current, (string) $arguments['role']]));
    }

    protected function preview(array $arguments): ProposalPreview
    {
        $user = $this->findUser((int) $arguments['user_id']);
        $this->findRole((string) $arguments['role']);
        Gate::forUser($this->actor())->authorize('assignRoles', $user);

        $current = $user->getRoleNames()->all();
        $roles = $this->targetRoles($arguments, $current);

        if ($roles === $current || (array_diff($roles, $current) === [] && array_diff($current, $roles) === [])) {
            throw ValidationException::withMessages(['role' => 'The user already has exactly these roles.']);
        }

        $this->ensureCanGrantRoles($this->actor(), array_merge(array_values(array_diff($roles, $current)), array_values(array_diff($current, $roles))));

        return new ProposalPreview(
            title: __('Give the role :role to :name', ['role' => $arguments['role'], 'name' => $user->name]),
            resourceType: 'user',
            resourceId: (string) $user->id,
            resourceLabel: "{$user->name} <{$user->email}>",
            resourceVersion: $this->userVersion($user),
            changes: [['field' => 'roles', 'before' => $current, 'after' => $roles]],
            effects: [__('The new permissions apply on the next request of the user.')],
            url: route('admin.users.edit', $user, false),
        );
    }

    protected function execute(array $arguments): array
    {
        $user = $this->findUser((int) $arguments['user_id']);
        $roles = $this->targetRoles($arguments, $user->getRoleNames()->all());

        $user = app(UpdateUser::class)->handle($this->actor(), $user, ['roles' => $roles]);

        return ['message' => 'Roles updated.', 'user' => $this->presentUser($user->load('roles'))];
    }
}
