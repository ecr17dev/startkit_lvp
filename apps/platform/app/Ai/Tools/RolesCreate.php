<?php

namespace App\Ai\Tools;

use App\Actions\Concerns\ChecksDelegation;
use App\Actions\Roles\CreateRole;
use App\Ai\Tools\Concerns\InteractsWithDirectory;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\Models\Role;
use Starter\Ai\Agents\OperatorTool;
use Starter\Ai\Agents\ProposalPreview;

class RolesCreate extends OperatorTool
{
    use ChecksDelegation, InteractsWithDirectory;

    public function description(): string
    {
        return 'Create a role with permissions from permissions_list (lowercase name with dashes). You can only grant permissions you hold. Requires the user\'s confirmation.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()->description('Lowercase letters, numbers and dashes.')->max(100)->required(),
            'permissions' => $schema->array()->items($schema->string())->description('Permission names.'),
        ];
    }

    protected function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => ['string', 'distinct'],
        ];
    }

    protected function preview(array $arguments): ProposalPreview
    {
        Gate::forUser($this->actor())->authorize('create', Role::class);
        $this->ensureCanGrantPermissions($this->actor(), $arguments['permissions'] ?? []);

        return new ProposalPreview(
            title: __('Create the role :name', ['name' => $arguments['name']]),
            resourceType: 'role',
            resourceLabel: (string) $arguments['name'],
            changes: [
                ['field' => 'name', 'before' => null, 'after' => $arguments['name']],
                ['field' => 'permissions', 'before' => null, 'after' => $arguments['permissions'] ?? []],
            ],
        );
    }

    protected function execute(array $arguments): array
    {
        $role = app(CreateRole::class)->handle($this->actor(), [
            'name' => $arguments['name'],
            'permissions' => $arguments['permissions'] ?? [],
        ]);

        return ['message' => 'Role created.', 'role' => $this->presentRole($role->load('permissions'))];
    }
}
