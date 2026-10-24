<?php

namespace App\Ai\Tools;

use App\Actions\Concerns\ChecksDelegation;
use App\Actions\Roles\UpdateRole;
use App\Ai\Tools\Concerns\InteractsWithDirectory;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Starter\Ai\Agents\OperatorTool;
use Starter\Ai\Agents\ProposalPreview;
use Starter\Identity\Permissions;

class RolesSetPermissions extends OperatorTool
{
    use ChecksDelegation, InteractsWithDirectory;

    public function description(): string
    {
        return 'Set the complete list of permissions of a role (names from permissions_list). You can only grant or revoke permissions you hold. Requires the user\'s confirmation.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'role' => $schema->string()->description('Name of the role.')->required(),
            'permissions' => $schema->array()->items($schema->string())->description('Complete list of permission names.')->required(),
        ];
    }

    protected function rules(): array
    {
        return [
            'role' => ['required', 'string', 'max:100'],
            'permissions' => ['present', 'array'],
            'permissions.*' => ['string', 'distinct'],
        ];
    }

    protected function preview(array $arguments): ProposalPreview
    {
        $role = $this->findRole((string) $arguments['role']);
        Gate::forUser($this->actor())->authorize('updatePermissions', $role);

        $unknown = array_diff($arguments['permissions'], Permissions::all());

        if ($unknown !== []) {
            throw ValidationException::withMessages(['permissions' => 'Unknown permissions: '.implode(', ', $unknown).'. Use permissions_list.']);
        }

        /** @var list<string> $current */
        $current = $role->permissions->pluck('name')->sort()->values()->all();
        $target = array_values(array_map('strval', (array) $arguments['permissions']));
        sort($target);

        $this->ensureCanGrantPermissions($this->actor(), array_merge(array_values(array_diff($target, $current)), array_values(array_diff($current, $target))));

        return new ProposalPreview(
            title: __('Change the permissions of :name', ['name' => $role->name]),
            resourceType: 'role',
            resourceId: (string) $role->id,
            resourceLabel: $role->name,
            resourceVersion: $this->roleVersion($role),
            changes: [
                ['field' => 'granted', 'before' => null, 'after' => array_values(array_diff($target, $current))],
                ['field' => 'revoked', 'before' => array_values(array_diff($current, $target)), 'after' => null],
            ],
            effects: [__('Every user with this role gets the new permissions on their next request.')],
            url: route('admin.roles.edit', $role, false),
        );
    }

    protected function execute(array $arguments): array
    {
        $role = app(UpdateRole::class)->handle($this->actor(), $this->findRole((string) $arguments['role']), ['permissions' => $arguments['permissions']], 'updatePermissions');

        return ['message' => 'Permissions updated.', 'role' => $this->presentRole($role->load('permissions'))];
    }
}
