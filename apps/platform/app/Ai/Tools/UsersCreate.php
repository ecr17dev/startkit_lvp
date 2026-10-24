<?php

namespace App\Ai\Tools;

use App\Actions\Concerns\ChecksDelegation;
use App\Actions\Users\CreateUser;
use App\Ai\Tools\Concerns\InteractsWithDirectory;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Gate;
use Starter\Ai\Agents\OperatorTool;
use Starter\Ai\Agents\ProposalPreview;

class UsersCreate extends OperatorTool
{
    use ChecksDelegation, InteractsWithDirectory;

    public function description(): string
    {
        return 'Create a user account with the exact name and email the user gave you (never invent or guess an email). The person signs in with a one-time code sent to that email.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()->description('Full name.')->max(255)->required(),
            'email' => $schema->string()->description('Email address, exactly as given by the user.')->max(255)->required(),
            'roles' => $schema->array()->items($schema->string())->description('Role names (see roles_list).'),
        ];
    }

    protected function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'roles' => ['nullable', 'array'],
            'roles.*' => ['string', 'distinct'],
        ];
    }

    protected function preview(array $arguments): ProposalPreview
    {
        Gate::forUser($this->actor())->authorize('create', User::class);
        $this->ensureCanGrantRoles($this->actor(), $arguments['roles'] ?? []);

        return new ProposalPreview(
            title: __('Create the user :email', ['email' => $arguments['email']]),
            resourceType: 'user',
            resourceLabel: (string) $arguments['email'],
            changes: [
                ['field' => 'name', 'before' => null, 'after' => $arguments['name']],
                ['field' => 'email', 'before' => null, 'after' => mb_strtolower((string) $arguments['email'])],
                ['field' => 'roles', 'before' => null, 'after' => $arguments['roles'] ?? []],
            ],
            effects: [__('The person can sign in with a one-time code sent to that email.')],
        );
    }

    protected function execute(array $arguments): array
    {
        $user = app(CreateUser::class)->handle($this->actor(), [
            'name' => $arguments['name'],
            'email' => mb_strtolower((string) $arguments['email']),
            'roles' => $arguments['roles'] ?? [],
        ]);

        return ['message' => 'User created.', 'user' => $this->presentUser($user->load('roles'))];
    }
}
