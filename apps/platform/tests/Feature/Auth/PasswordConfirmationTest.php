<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PasswordConfirmationTest extends TestCase
{
    use RefreshDatabase;

    public function test_confirm_screen_can_be_rendered(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('password.confirm'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('auth/ConfirmPassword')
                ->where('canUsePassword', false)
                ->where('codeSent', false));
    }

    public function test_confirmation_requires_authentication(): void
    {
        $this->get(route('password.confirm'))->assertRedirect(route('login'));
    }

    public function test_sensitive_actions_can_be_confirmed_with_a_code(): void
    {
        Notification::fake();
        $user = User::factory()->withoutPassword()->create();

        $this->actingAs($user)->get(route('security.edit'))->assertRedirect(route('password.confirm'));

        $this->actingAs($user)->post(route('password.confirm.code'))->assertRedirect();

        $this->actingAs($user)
            ->post(route('password.confirm.code.verify'), ['code' => $this->codeSentTo($user)])
            ->assertRedirect(route('security.edit'));

        $this->actingAs($user)->get(route('security.edit'))->assertOk();
    }

    public function test_wrong_confirmation_codes_are_rejected(): void
    {
        Notification::fake();
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('password.confirm.code'));
        $code = $this->codeSentTo($user);

        $this->actingAs($user)
            ->post(route('password.confirm.code.verify'), ['code' => $code === '000000' ? '111111' : '000000'])
            ->assertSessionHasErrors('code');

        $this->assertNull(session('auth.password_confirmed_at'));
    }

    public function test_password_confirmation_is_only_available_when_enabled(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('password.confirm.store'), ['password' => 'password'])->assertNotFound();

        $this->enablePasswordLogin();

        $this->actingAs($user)->post(route('password.confirm.store'), ['password' => 'password'])->assertRedirect();
    }
}
