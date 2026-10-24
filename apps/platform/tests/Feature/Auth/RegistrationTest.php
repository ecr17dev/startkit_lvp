<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Starter\Identity\Notifications\OneTimeCodeNotification;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        $this->seedPermissions();
    }

    public function test_sign_up_is_not_available_by_default(): void
    {
        $this->get(route('register'))->assertNotFound();
        $this->post(route('register.store'), ['name' => 'Ana', 'email' => 'ana@example.com'])->assertNotFound();
    }

    public function test_people_can_sign_up_with_a_code_when_registration_is_open(): void
    {
        settings()->set(['auth.registration' => 'open']);

        $this->get(route('register'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('auth/Register'));

        $this->post(route('register.store'), ['name' => 'Ana Pérez', 'email' => 'ana@example.com'])
            ->assertRedirect(route('login.verify'));

        // No account exists until the email is verified...
        $this->assertDatabaseMissing('users', ['email' => 'ana@example.com']);

        $this->post(route('login.verify.store'), ['code' => $this->codeSentToEmail('ana@example.com')])
            ->assertRedirect(route('dashboard', absolute: false));

        $user = User::query()->where('email', 'ana@example.com')->sole();

        $this->assertAuthenticatedAs($user);
        $this->assertSame('Ana Pérez', $user->name);
        $this->assertNotNull($user->email_verified_at);
        $this->assertSame(['reader'], $user->getRoleNames()->all());
        $this->assertNull($user->password);
    }

    public function test_signing_up_with_an_existing_email_sends_a_login_code_instead(): void
    {
        settings()->set(['auth.registration' => 'open']);
        $user = User::factory()->create();

        $this->post(route('register.store'), ['name' => 'Someone', 'email' => $user->email])
            ->assertRedirect(route('login.verify'));

        Notification::assertSentTo($user, OneTimeCodeNotification::class);

        $this->post(route('login.verify.store'), ['code' => $this->codeSentTo($user)]);

        $this->assertAuthenticatedAs($user);
        $this->assertSame(1, User::query()->where('email', $user->email)->count());
        $this->assertNotSame('Someone', $user->refresh()->name);
    }

    public function test_closing_registration_stops_pending_sign_ups(): void
    {
        settings()->set(['auth.registration' => 'open']);

        $this->post(route('register.store'), ['name' => 'Ana', 'email' => 'ana@example.com']);
        $code = $this->codeSentToEmail('ana@example.com');

        settings()->set(['auth.registration' => 'invite-only']);

        $this->post(route('login.verify.store'), ['code' => $code])->assertSessionHasErrors('code');
        $this->assertDatabaseMissing('users', ['email' => 'ana@example.com']);
        $this->assertGuest();
    }

    public function test_self_registered_users_never_receive_privileged_roles(): void
    {
        settings()->set(['auth.registration' => 'open', 'auth.default_role' => 'owner']);

        $this->post(route('register.store'), ['name' => 'Eve', 'email' => 'eve@example.com']);
        $this->post(route('login.verify.store'), ['code' => $this->codeSentToEmail('eve@example.com')]);

        $this->assertSame(['reader'], User::query()->where('email', 'eve@example.com')->sole()->getRoleNames()->all());
    }
}
