<?php

namespace App\Ai\Tools;

use App\Ai\Tools\Concerns\InteractsWithDirectory;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Gate;
use Starter\Ai\Agents\OperatorTool;

class RolesGet extends OperatorTool
{
    use InteractsWithDirectory;

    public function description(): string
    {
        return 'Get one role with its permissions.';
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

    protected function execute(array $arguments): array
    {
        $role = $this->findRole((string) $arguments['role']);

        Gate::forUser($this->actor())->authorize('view', $role);

        return ['role' => $this->presentRole($role)];
    }
}
