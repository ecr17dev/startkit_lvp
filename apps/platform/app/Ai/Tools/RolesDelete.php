<?php

namespace App\Ai\Tools;

use App\Actions\Roles\DeleteRole;
use App\Ai\Tools\Concerns\InteractsWithDirectory;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Gate;
use Starter\Ai\Agents\OperatorTool;
use Starter\Ai\Agents\ProposalPreview;

class RolesDelete extends OperatorTool
{
    use InteractsWithDirectory;

    public function description(): string
    {
        return 'Delete a role that is not a system role. Its users lose its permissions. Requires the user\'s confirmation.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'role' => $schema->string()->description('Name of the role.')->required(),
        ];
    }

    protected function rules(): array
    {
        return ['role' => ['required', 'string', 'max:100']];
    }

    protected function preview(array $arguments): ProposalPreview
    {
        $role = $this->findRole((string) $arguments['role']);
        Gate::forUser($this->actor())->authorize('delete', $role);
        $users = $role->users()->count();

        return new ProposalPreview(
            title: __('Delete the role :name', ['name' => $role->name]),
            resourceType: 'role',
            resourceId: (string) $role->id,
            resourceLabel: $role->name,
            resourceVersion: $this->roleVersion($role),
            changes: [['field' => 'role', 'before' => $role->name, 'after' => null]],
            effects: [__(':count user(s) lose the permissions of this role.', ['count' => $users]), __('This cannot be undone.')],
        );
    }

    protected function execute(array $arguments): array
    {
        $role = $this->findRole((string) $arguments['role']);
        $name = $role->name;

        app(DeleteRole::class)->handle($this->actor(), $role);

        return ['message' => "Role {$name} deleted."];
    }
}
