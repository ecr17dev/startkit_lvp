<?php

namespace App\Actions\Ai;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Starter\Ai\Models\Connection;

/**
 * Delete a connection and its catalog. The ledger keeps its history.
 */
class DeleteConnection
{
    public function handle(User $actor, Connection $connection): void
    {
        Gate::forUser($actor)->authorize('ai.providers.manage');

        $name = $connection->name;
        $connection->delete();

        audit('ai.connections.deleted', $connection, ['name' => $name], $actor);
    }
}
