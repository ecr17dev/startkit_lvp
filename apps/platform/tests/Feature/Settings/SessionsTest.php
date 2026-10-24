<?php

namespace Tests\Feature\Settings;

use App\Actions\Users\RevokeSessions;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SessionsTest extends TestCase
{
    use RefreshDatabase;

    protected function addSession(User $user, string $id): void
    {
        DB::table('sessions')->insert([
            'id' => $id, 'user_id' => $user->id, 'ip_address' => '192.168.1.10',
            'user_agent' => 'Mozilla/5.0 (iPhone) Safari/604', 'payload' => '', 'last_activity' => now()->getTimestamp(),
        ]);
    }

    public function test_users_see_their_sessions_without_raw_ids(): void
    {
        $user = User::factory()->create();
        $this->addSession($user, 'secret-session-id');

        $this->actingAs($user)
            ->get(route('sessions.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('settings/Sessions')
                ->has('sessions', 1)
                ->where('sessions.0.id', RevokeSessions::publicId('secret-session-id'))
                ->where('sessions.0.platform', 'iOS'));
    }

    public function test_users_can_revoke_their_sessions(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $this->addSession($user, 'mine-1');
        $this->addSession($user, 'mine-2');
        $this->addSession($other, 'theirs');

        $this->actingAs($user)
            ->delete(route('sessions.destroy', RevokeSessions::publicId('mine-1')))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('sessions', ['id' => 'mine-1']);

        // A session of another user cannot be targeted by its public id...
        $this->actingAs($user)->delete(route('sessions.destroy', RevokeSessions::publicId('theirs')));
        $this->assertDatabaseHas('sessions', ['id' => 'theirs']);

        $this->actingAs($user)->delete(route('sessions.destroy-others'))->assertSessionHasNoErrors();
        $this->assertDatabaseMissing('sessions', ['id' => 'mine-2']);
        $this->assertDatabaseHas('sessions', ['id' => 'theirs']);
    }
}
