<?php

namespace App\Ai\Tools;

use App\Ai\Tools\Concerns\InteractsWithDirectory;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Starter\Ai\Agents\OperatorTool;

class UsersGet extends OperatorTool
{
    use InteractsWithDirectory;

    public function description(): string
    {
        return 'Get one user by id or exact email address.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'user_id' => $schema->integer()->description('Id of the user.'),
            'email' => $schema->string()->description('Exact email address.')->max(255),
        ];
    }

    protected function rules(): array
    {
        return [
            'user_id' => ['nullable', 'integer', 'required_without:email'],
            'email' => ['nullable', 'string', 'max:255'],
        ];
    }

    protected function execute(array $arguments): array
    {
        $user = isset($arguments['user_id'])
            ? $this->findUser((int) $arguments['user_id'])
            : User::query()->with('roles')->where('email', mb_strtolower((string) $arguments['email']))->first();

        if ($user === null) {
            throw ValidationException::withMessages(['email' => 'No user exists with that email address.']);
        }

        Gate::forUser($this->actor())->authorize('view', $user);

        return ['user' => $this->presentUser($user)];
    }
}
