<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Starter\Identity\Models\Invitation;
use Starter\Identity\Notifications\InvitationNotification;
use Tests\TestCase;

class InvitationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
    }

    /**
     * Invite someone from the panel and return the link token.
     */
    protected function invite(User $admin, string $email = 'new@example.com', string $role = 'editor'): string
    {
        $this->actingAs($admin)
            ->post(route('admin.invitations.store'), ['email' => $email, 'role' => $role])
            ->assertSessionHasNoErrors();

        $token = null;

        Notification::assertSentOnDemand(InvitationNotification::class, function (InvitationNotification $notification) use (&$token) {
            $token = basename($notification->url);

            return true;
        });

        $this->app['auth']->forgetGuards();

        return (string) $token;
    }

    public function test_administrators_can_invite_people_with_a_role_they_can_grant(): void
    {
        $admin = $this->userWithRole('admin');

        $this->invite($admin);

        $invitation = Invitation::query()->sole();
        $this->assertSame('new@example.com', $invitation->email);
        $this->assertSame('editor', $invitation->role);
        $this->assertSame($admin->id, $invitation->invited_by);
        $this->assertTrue($invitation->isPending());
        $this->assertDatabaseHas('audit_events', ['action' => 'invitations.created', 'actor_id' => $admin->id]);
    }

    public function test_the_owner_role_cannot_be_granted_by_administrators(): void
    {
        $admin = $this->userWithRole('admin');

        $this->actingAs($admin)
            ->post(route('admin.invitations.store'), ['email' => 'x@example.com', 'role' => 'owner'])
            ->assertSessionHasErrors('role');

        $this->assertSame(0, Invitation::query()->count());
    }

    public function test_existing_users_cannot_be_invited(): void
    {
        $admin = $this->userWithRole('admin');
        $existing = User::factory()->create();

        $this->actingAs($admin)
            ->post(route('admin.invitations.store'), ['email' => $existing->email, 'role' => 'reader'])
            ->assertSessionHasErrors('email');
    }

    public function test_readers_cannot_invite(): void
    {
        $reader = $this->userWithRole('reader');

        $this->actingAs($reader)
            ->post(route('admin.invitations.store'), ['email' => 'x@example.com', 'role' => 'reader'])
            ->assertForbidden();
    }

    public function test_invited_people_create_their_account_with_a_code(): void
    {
        $token = $this->invite($this->userWithRole('admin'));

        $this->get(route('invitations.show', $token))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('auth/AcceptInvitation')
                ->where('invitation.email', 'new@example.com')
                ->where('invitation.role', 'editor'));

        $this->post(route('invitations.accept', $token), ['name' => 'New Person'])
            ->assertRedirect(route('login.verify'));

        $this->post(route('login.verify.store'), ['code' => $this->codeSentToEmail('new@example.com')])
            ->assertRedirect(route('dashboard', absolute: false));

        $user = User::query()->where('email', 'new@example.com')->sole();

        $this->assertAuthenticatedAs($user);
        $this->assertSame('New Person', $user->name);
        $this->assertSame(['editor'], $user->getRoleNames()->all());
        $this->assertNotNull(Invitation::query()->sole()->accepted_at);
        $this->assertSame($user->id, Invitation::query()->sole()->accepted_user_id);
    }

    public function test_invalid_tokens_and_revoked_invitations_cannot_be_used(): void
    {
        $admin = $this->userWithRole('admin');
        $token = $this->invite($admin);

        $this->get(route('invitations.show', 'not-a-token'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('invitation', null));

        $this->actingAs($admin)->delete(route('admin.invitations.destroy', Invitation::query()->sole()));
        $this->app['auth']->forgetGuards();

        $this->get(route('invitations.show', $token))
            ->assertInertia(fn (Assert $page) => $page->where('invitation', null));

        $this->post(route('invitations.accept', $token), ['name' => 'Late'])->assertNotFound();
    }

    public function test_resending_an_invitation_replaces_the_link(): void
    {
        $admin = $this->userWithRole('admin');
        $old = $this->invite($admin);

        $this->travel(2)->minutes();

        $this->actingAs($admin)
            ->post(route('admin.invitations.resend', Invitation::query()->sole()))
            ->assertSessionHasNoErrors();

        $this->assertNull(Invitation::findPendingByToken($old));
        Notification::assertSentOnDemandTimes(InvitationNotification::class, 2);
    }

    public function test_invitations_are_disabled_when_registration_is_closed(): void
    {
        $admin = $this->userWithRole('admin');
        $token = $this->invite($admin);

        settings()->set(['auth.registration' => 'closed']);

        $this->post(route('invitations.accept', $token), ['name' => 'Late'])->assertNotFound();

        $this->actingAs($admin)
            ->post(route('admin.invitations.store'), ['email' => 'other@example.com', 'role' => 'reader'])
            ->assertSessionHasErrors('email');
    }
}
