<?php

namespace Tests\Feature\Settings;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProfileUpdateTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_page_is_displayed()
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->get(route('profile.edit'));

        $response->assertOk();
    }

    public function test_profile_information_can_be_updated()
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->patch(route('profile.update'), [
                'name' => 'Test User',
                'email' => 'test@example.com',
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('profile.edit'));

        $user->refresh();

        $this->assertSame('Test User', $user->name);
        $this->assertSame('test@example.com', $user->email);
        $this->assertNull($user->email_verified_at);
    }

    public function test_email_verification_status_is_unchanged_when_the_email_address_is_unchanged()
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->patch(route('profile.update'), [
                'name' => 'Test User',
                'email' => $user->email,
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('profile.edit'));

        $this->assertNotNull($user->refresh()->email_verified_at);
    }

    public function test_deleting_the_account_requires_a_recent_confirmation()
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->delete(route('profile.destroy'))
            ->assertRedirect(route('password.confirm'));

        $this->assertNotSoftDeleted($user);
    }

    public function test_user_can_delete_their_account()
    {
        $user = User::factory()->create();
        $email = $user->email;

        $response = $this
            ->actingAs($user)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->delete(route('profile.destroy'));

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('home'));

        $this->assertGuest();
        $this->assertSoftDeleted($user);

        // The account is anonymized and soft deleted...
        $deleted = User::withTrashed()->find($user->id);
        $this->assertNotNull($deleted);
        $this->assertNotSame($email, $deleted->email);
        $this->assertFalse($deleted->is_active);
    }

    public function test_the_last_owner_cannot_delete_their_account()
    {
        $owner = $this->userWithRole('owner');

        $this->actingAs($owner)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->from(route('profile.edit'))
            ->delete(route('profile.destroy'))
            ->assertSessionHasErrors('account');

        $this->assertNotSoftDeleted($owner);
    }
}
