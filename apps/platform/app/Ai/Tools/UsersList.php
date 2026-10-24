<?php

namespace App\Ai\Tools;

use App\Ai\Tools\Concerns\InteractsWithDirectory;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Gate;
use Starter\Ai\Agents\OperatorTool;

class UsersList extends OperatorTool
{
    use InteractsWithDirectory;

    public function description(): string
    {
        return 'Search users by name or email, role and status. Always search before changing a user: if several users match, show the candidates and ask which one.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'search' => $schema->string()->description('Part of the name or email.')->max(100),
            'role' => $schema->string()->description('Only users with this role.')->max(100),
            'status' => $schema->string()->enum(['active', 'inactive', 'any'])->description('Account status (default any).'),
            'limit' => $schema->integer()->min(1)->max(50)->description('Maximum results (default 20).'),
        ];
    }

    protected function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:100'],
            'role' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'in:active,inactive,any'],
            'limit' => ['nullable', 'integer', 'between:1,50'],
        ];
    }

    protected function execute(array $arguments): array
    {
        Gate::forUser($this->actor())->authorize('viewAny', User::class);

        $search = $arguments['search'] ?? null;
        $query = User::query()
            ->with('roles')
            ->when($search, fn ($query, string $term) => $query->where(fn ($query) => $query
                ->whereLike('name', "%{$term}%")
                ->orWhereLike('email', "%{$term}%")))
            ->when($arguments['role'] ?? null, fn ($query, string $role) => $query->whereHas('roles', fn ($query) => $query->where('name', $role)))
            ->when(($arguments['status'] ?? 'any') !== 'any', fn ($query) => $query->where('is_active', ($arguments['status'] ?? null) === 'active'))
            ->orderBy('name');

        $total = (clone $query)->count();
        $users = $query->limit((int) ($arguments['limit'] ?? 20))->get();

        return [
            'total' => $total,
            'users' => $users->map(fn (User $user) => $this->presentUser($user))->all(),
        ];
    }
}
