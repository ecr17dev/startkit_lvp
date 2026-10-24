<?php

namespace App\Ai\Tools;

use App\Ai\Tools\Concerns\InteractsWithDirectory;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\Models\Role;
use Starter\Ai\Agents\OperatorTool;

class RolesList extends OperatorTool
{
    use InteractsWithDirectory;

    public function description(): string
    {
        return 'List the roles with their permissions and number of users.';
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
        Gate::forUser($this->actor())->authorize('viewAny', Role::class);

        return [
            'roles' => Role::query()->with('permissions')->orderBy('id')->get()->map(fn (Role $role) => $this->presentRole($role))->all(),
        ];
    }
}
