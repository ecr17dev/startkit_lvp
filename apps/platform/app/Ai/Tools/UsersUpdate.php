<?php

namespace App\Ai\Tools;

use App\Actions\Users\UpdateUser;
use App\Ai\Tools\Concerns\InteractsWithDirectory;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Gate;
use Starter\Ai\Agents\OperatorTool;
use Starter\Ai\Agents\ProposalPreview;

class UsersUpdate extends OperatorTool
{
    use InteractsWithDirectory;

    public function description(): string
    {
        return 'Change the name, email or language of a user found with users_list. Roles are changed with users_assign_role and users_remove_role.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'user_id' => $schema->integer()->description('Id of the user.')->required(),
            'name' => $schema->string()->max(255),
            'email' => $schema->string()->max(255)->description('New email; it must be verified again.'),
            'locale' => $schema->string()->enum(array_keys((array) config('starter.locales'))),
        ];
    }

    protected function rules(): array
    {
        return [
            'user_id' => ['required', 'integer'],
            'name' => ['sometimes', 'string', 'max:255'],
            'email' => ['sometimes', 'string', 'email', 'max:255'],
            'locale' => ['sometimes', 'string', 'max:10'],
        ];
    }

    protected function preview(array $arguments): ProposalPreview
    {
        $user = $this->findUser((int) $arguments['user_id']);
        Gate::forUser($this->actor())->authorize('update', $user);

        $changes = [];

        foreach (['name', 'email', 'locale'] as $field) {
            if (array_key_exists($field, $arguments)) {
                $changes[] = ['field' => $field, 'before' => $user->{$field}, 'after' => $field === 'email' ? mb_strtolower((string) $arguments[$field]) : $arguments[$field]];
            }
        }

        return new ProposalPreview(
            title: __('Update :name', ['name' => $user->name]),
            resourceType: 'user',
            resourceId: (string) $user->id,
            resourceLabel: "{$user->name} <{$user->email}>",
            resourceVersion: $this->userVersion($user),
            changes: $changes,
            effects: array_key_exists('email', $arguments) ? [__('The new email must be verified again.')] : [],
            url: route('admin.users.edit', $user, false),
        );
    }

    protected function execute(array $arguments): array
    {
        $user = $this->findUser((int) $arguments['user_id']);

        $input = array_intersect_key($arguments, array_flip(['name', 'email', 'locale']));

        if (isset($input['email'])) {
            $input['email'] = mb_strtolower((string) $input['email']);
        }

        $user = app(UpdateUser::class)->handle($this->actor(), $user, $input);

        return ['message' => 'User updated.', 'user' => $this->presentUser($user->load('roles'))];
    }
}
