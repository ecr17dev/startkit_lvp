<?php

namespace Tests\Feature\Admin;

use App\Actions\Users\RevokeSessions;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Starter\Administration\Models\AuditEvent;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_users_without_permission_see_the_access_denied_page(): void
    {
        $reader = $this->userWithRole('reader');

        $this->actingAs($reader)
            ->get(route('admin.users.index'))
            ->assertForbidden()
            ->assertInertia(fn (Assert $page) => $page->component('errors/Error')->where('status', 403));
    }

    public function test_administrators_can_list_and_filter_users(): void
    {
        $admin = $this->userWithRole('admin');
        User::factory()->create(['name' => 'Zoe Match']);
        User::factory()->inactive()->create(['name' => 'Disabled Person']);

        $this->actingAs($admin)
            ->get(route('admin.users.index', ['search' => 'zoe']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/users/Index')
                ->has('users.data', 1)
                ->where('users.data.0.name', 'Zoe Match'));

        $this->actingAs($admin)
            ->get(route('admin.users.index', ['status' => 'disabled']))
            ->assertInertia(fn (Assert $page) => $page->has('users.data', 1)->where('users.data.0.name', 'Disabled Person'));
    }

    public function test_administrators_create_users_with_roles_they_can_grant(): void
    {
        $admin = $this->userWithRole('admin');

        $this->actingAs($admin)
            ->post(route('admin.users.store'), ['name' => 'Eva', 'email' => 'eva@example.com', 'roles' => ['editor']])
            ->assertSessionHasNoErrors();

        $user = User::query()->where('email', 'eva@example.com')->sole();
        $this->assertSame(['editor'], $user->getRoleNames()->all());
        $this->assertNull($user->password);
        $this->assertDatabaseHas('audit_events', ['action' => 'users.created', 'actor_id' => $admin->id, 'subject_id' => (string) $user->id]);

        $this->actingAs($admin)
            ->post(route('admin.users.store'), ['name' => 'Mal', 'email' => 'mal@example.com', 'roles' => ['owner']])
            ->assertSessionHasErrors('roles');

        $this->assertDatabaseMissing('users', ['email' => 'mal@example.com']);
    }

    public function test_passwords_cannot_be_set_when_the_deployment_disables_them(): void
    {
        $admin = $this->userWithRole('admin');

        $this->actingAs($admin)
            ->post(route('admin.users.store'), ['name' => 'Eva', 'email' => 'eva@example.com', 'password' => 'Secret-123456'])
            ->assertSessionHasErrors('password');
    }

    public function test_only_owners_can_manage_owners(): void
    {
        $admin = $this->userWithRole('admin');
        $owner = $this->userWithRole('owner');

        $this->actingAs($admin)->put(route('admin.users.update', $owner), ['name' => 'Hacked'])->assertForbidden();
        $this->actingAs($admin)->patch(route('admin.users.status', $owner), ['active' => false])->assertForbidden();
        $this->actingAs($admin)->delete(route('admin.users.destroy', $owner))->assertForbidden();

        $this->assertNotSame('Hacked', $owner->refresh()->name);
        $this->assertTrue($owner->is_active);
    }

    public function test_nobody_changes_their_own_roles(): void
    {
        $admin = $this->userWithRole('admin');

        $this->actingAs($admin)
            ->put(route('admin.users.update', $admin), ['name' => $admin->name, 'roles' => ['admin', 'owner']])
            ->assertForbidden();

        $this->assertSame(['admin'], $admin->refresh()->getRoleNames()->all());
    }

    public function test_roles_with_permissions_the_actor_lacks_cannot_be_granted(): void
    {
        $admin = $this->userWithRole('admin');
        $target = $this->userWithRole('reader');

        // A custom role holding a permission the admin role does not have...
        $owner = $this->userWithRole('owner');
        $this->actingAs($owner)->post(route('admin.roles.store'), ['name' => 'matrix-editor', 'permissions' => ['permissions.manage']]);

        $this->actingAs($admin)
            ->put(route('admin.users.update', $target), ['roles' => ['reader', 'matrix-editor']])
            ->assertSessionHasErrors('roles');

        $this->actingAs($admin)
            ->put(route('admin.users.update', $target), ['roles' => ['editor']])
            ->assertSessionHasNoErrors();

        $this->assertSame(['editor'], $target->refresh()->getRoleNames()->all());
    }

    public function test_disabling_a_user_revokes_sessions_and_tokens(): void
    {
        $admin = $this->userWithRole('admin');
        $user = $this->userWithRole('editor');
        $user->createToken('cli');

        DB::table('sessions')->insert([
            'id' => 'session-of-user', 'user_id' => $user->id, 'ip_address' => '127.0.0.1',
            'user_agent' => 'Firefox/120', 'payload' => '', 'last_activity' => now()->getTimestamp(),
        ]);

        $this->actingAs($admin)
            ->patch(route('admin.users.status', $user), ['active' => false])
            ->assertSessionHasNoErrors();

        $user->refresh();
        $this->assertFalse($user->is_active);
        $this->assertNotNull($user->disabled_at);
        $this->assertSame(0, $user->tokens()->count());
        $this->assertDatabaseMissing('sessions', ['id' => 'session-of-user']);
        $this->assertDatabaseHas('audit_events', ['action' => 'users.disabled', 'subject_id' => (string) $user->id]);

        // A disabled user still holding a session is logged out on the next request...
        $this->actingAs($user)->get(route('dashboard'))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_deleting_a_user_anonymizes_the_account(): void
    {
        $admin = $this->userWithRole('admin');
        $user = $this->userWithRole('editor', ['name' => 'Private Name']);
        $email = $user->email;

        $this->actingAs($admin)
            ->delete(route('admin.users.destroy', $user))
            ->assertRedirect(route('admin.users.index'));

        $deleted = User::withTrashed()->findOrFail($user->id);
        $this->assertTrue($deleted->trashed());
        $this->assertNotSame('Private Name', $deleted->name);
        $this->assertStringEndsWith('@users.invalid', $deleted->email);
        $this->assertSame([], $deleted->getRoleNames()->all());

        $event = AuditEvent::query()->where('action', 'users.deleted')->sole();
        $this->assertSame(hash('sha256', $email), $event->properties['email_sha256'] ?? null);
        $this->assertStringNotContainsString($email, json_encode($event->properties) ?: '');
    }

    public function test_nobody_disables_or_deletes_their_own_account_from_the_panel(): void
    {
        $admin = $this->userWithRole('admin');

        $this->actingAs($admin)->patch(route('admin.users.status', $admin), ['active' => false])->assertForbidden();
        $this->actingAs($admin)->delete(route('admin.users.destroy', $admin))->assertForbidden();
    }

    public function test_the_user_detail_shows_sessions_and_activity(): void
    {
        $owner = $this->userWithRole('owner');
        $user = $this->userWithRole('editor');

        DB::table('sessions')->insert([
            'id' => 'abc', 'user_id' => $user->id, 'ip_address' => '10.0.0.1',
            'user_agent' => 'Mozilla/5.0 (Macintosh) Chrome/120 Safari/537', 'payload' => '', 'last_activity' => now()->getTimestamp(),
        ]);

        $this->actingAs($owner)
            ->get(route('admin.users.edit', $user))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/users/Edit')
                ->has('sessions', 1)
                ->where('sessions.0.browser', 'Chrome')
                ->where('sessions.0.platform', 'macOS')
                ->where('can.assignRoles', true)
                ->where('isLastOwner', false)
                ->has('activity'));

        $sessionId = RevokeSessions::publicId('abc');

        $this->actingAs($owner)
            ->delete(route('admin.users.sessions.destroy', [$user, $sessionId]))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('sessions', ['id' => 'abc']);
    }
}
