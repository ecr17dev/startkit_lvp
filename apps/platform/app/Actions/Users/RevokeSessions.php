<?php

namespace App\Actions\Users;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Revoke browser sessions (database driver) and API tokens of a user.
 */
class RevokeSessions
{
    /**
     * Revoke one session, identified by its public id (a hash of the session id).
     */
    public function handle(User $actor, User $user, string $publicId): bool
    {
        Gate::forUser($actor)->authorize('manageSessions', $user);

        $sessionId = DB::table($this->table())
            ->where('user_id', $user->id)
            ->pluck('id')
            ->first(fn (string $id) => hash_equals(self::publicId($id), $publicId));

        if ($sessionId === null) {
            return false;
        }

        DB::table($this->table())->where('id', $sessionId)->delete();

        audit('sessions.revoked', $user, ['count' => 1], $actor);

        return true;
    }

    /**
     * Revoke the sessions of the user, optionally keeping the current one.
     */
    public function others(User $actor, User $user, ?string $exceptSessionId = null): int
    {
        Gate::forUser($actor)->authorize('manageSessions', $user);

        $count = DB::table($this->table())
            ->where('user_id', $user->id)
            ->when($exceptSessionId, fn ($query, string $id) => $query->where('id', '!=', $id))
            ->delete();

        $user->forceFill(['remember_token' => null])->saveQuietly();

        audit('sessions.revoked', $user, ['count' => $count], $actor);

        return $count;
    }

    /**
     * Revoke every session and API token (used when disabling or deleting).
     */
    public function revokeAll(User $user): void
    {
        DB::table($this->table())->where('user_id', $user->id)->delete();
        $user->tokens()->delete();
        $user->forceFill(['remember_token' => null])->saveQuietly();
    }

    /**
     * The identifier shown in the interface. Raw session ids are secrets.
     */
    public static function publicId(string $sessionId): string
    {
        return substr(hash_hmac('sha256', $sessionId, (string) config('app.key')), 0, 32);
    }

    protected function table(): string
    {
        return (string) config('session.table', 'sessions');
    }
}
