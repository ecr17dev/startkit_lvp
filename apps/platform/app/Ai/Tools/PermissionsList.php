<?php

namespace App\Ai\Tools;

use App\Ai\Tools\Concerns\InteractsWithDirectory;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Starter\Ai\Agents\OperatorTool;
use Starter\Identity\Permissions;

class PermissionsList extends OperatorTool
{
    use InteractsWithDirectory;

    public function description(): string
    {
        return 'List the implemented permissions grouped by module. Only these names exist: never invent a permission.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    protected function rules(): array
    {
        return [];
    }

    protected function execute(array $arguments): array
    {
        if (! $this->actor()->can('roles.read') && ! $this->actor()->can('permissions.read')) {
            throw new AuthorizationException;
        }

        return ['permissions' => Permissions::grouped()];
    }
}
