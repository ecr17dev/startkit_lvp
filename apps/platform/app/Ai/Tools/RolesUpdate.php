<?php

namespace App\Ai\Tools;

use App\Actions\Roles\UpdateRole;
use App\Ai\Tools\Concerns\InteractsWithDirectory;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Gate;
use Starter\Ai\Agents\OperatorTool;
use Starter\Ai\Agents\ProposalPreview;

class RolesUpdate extends OperatorTool
{
    use InteractsWithDirectory;

    public function description(): string
    {
        return 'Rename a role (system roles cannot be renamed). Change permissions with roles_set_permissions. Requires the user\'s confirmation.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'role' => $schema->string()->description('Current name of the role.')->required(),
            'name' => $schema->string()->description('New name: lowercase letters, numbers and dashes.')->max(100)->required(),
        ];
    }

    protected function rules(): array
    {
        return [
            'role' => ['required', 'string', 'max:100'],
            'name' => ['required', 'string', 'max:100'],
        ];
    }

    protected function preview(array $arguments): ProposalPreview
    {
        $role = $this->findRole((string) $arguments['role']);
        Gate::forUser($this->actor())->authorize('update', $role);

        return new ProposalPreview(
            title: __('Rename the role :name', ['name' => $role->name]),
            resourceType: 'role',
            resourceId: (string) $role->id,
            resourceLabel: $role->name,
            resourceVersion: $this->roleVersion($role),
            changes: [['field' => 'name', 'before' => $role->name, 'after' => $arguments['name']]],
            url: route('admin.roles.edit', $role, false),
        );
    }

    protected function execute(array $arguments): array
    {
        $role = app(UpdateRole::class)->handle($this->actor(), $this->findRole((string) $arguments['role']), ['name' => $arguments['name']]);

        return ['message' => 'Role renamed.', 'role' => $this->presentRole($role->load('permissions'))];
    }
}
