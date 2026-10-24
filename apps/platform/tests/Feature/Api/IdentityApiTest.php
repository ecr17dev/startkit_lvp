<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Starter\Identity\Models\LoginChallenge;
use Tests\TestCase;

class IdentityApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_requesting_a_code_never_reveals_whether_the_account_exists(): void
    {
        Notification::fake();
        $user = $this->userWithRole('editor');

        $known = $this->postJson(route('api.v1.auth.otp'), ['email' => $user->email])->assertStatus(202);
        $unknown = $this->postJson(route('api.v1.auth.otp'), ['email' => 'ghost@example.com'])->assertStatus(202);

        $this->assertSame(array_keys($known->json()), array_keys($unknown->json()));
        $this->assertTrue(LoginChallenge::query()->whereKey($unknown->json('challenge_id'))->exists());
    }

    public function test_codes_are_exchanged_for_tokens(): void
    {
        Notification::fake();
        $user = $this->userWithRole('editor');

        $challenge = $this->postJson(route('api.v1.auth.otp'), ['email' => $user->email])->json('challenge_id');

        $response = $this->postJson(route('api.v1.auth.otp.verify'), [
            'challenge_id' => $challenge,
            'code' => $this->codeSentTo($user),
            'device_name' => 'cli',
            'abilities' => ['read'],
        ])->assertCreated();

        $this->assertSame(['read'], $response->json('abilities'));

        $this->withToken($response->json('token'))
            ->getJson(route('api.v1.me'))
            ->assertOk()
            ->assertJsonPath('data.email', $user->email)
            ->assertJsonFragment(['users.read']);
    }

    public function test_tokens_require_the_token_permission(): void
    {
        Notification::fake();
        $reader = $this->userWithRole('reader');

        $challenge = $this->postJson(route('api.v1.auth.otp'), ['email' => $reader->email])->json('challenge_id');

        $this->postJson(route('api.v1.auth.otp.verify'), [
            'challenge_id' => $challenge,
            'code' => $this->codeSentTo($reader),
            'device_name' => 'cli',
        ])->assertForbidden();
    }

    public function test_wrong_codes_are_rejected(): void
    {
        Notification::fake();
        $user = $this->userWithRole('editor');
        $challenge = $this->postJson(route('api.v1.auth.otp'), ['email' => $user->email])->json('challenge_id');
        $code = $this->codeSentTo($user);

        $this->postJson(route('api.v1.auth.otp.verify'), [
            'challenge_id' => $challenge,
            'code' => $code === '000000' ? '111111' : '000000',
            'device_name' => 'cli',
        ])->assertUnprocessable()->assertJsonValidationErrors('code');
    }

    public function test_password_tokens_are_disabled_by_default(): void
    {
        $user = User::factory()->create();

        $this->postJson(route('api.v1.auth.token'), [
            'email' => $user->email, 'password' => 'password', 'device_name' => 'cli',
        ])->assertNotFound();
    }

    public function test_user_administration_uses_the_same_rules_as_the_panel(): void
    {
        $admin = $this->userWithRole('admin');
        $owner = $this->userWithRole('owner');

        Sanctum::actingAs($admin, ['read', 'write']);

        $this->getJson(route('api.v1.users.index'))->assertOk()->assertJsonCount(2, 'data');

        $this->postJson(route('api.v1.users.store'), ['name' => 'Api User', 'email' => 'api@example.com', 'roles' => ['reader']])
            ->assertCreated()
            ->assertJsonPath('data.roles', ['reader']);

        $this->postJson(route('api.v1.users.store'), ['name' => 'Bad', 'email' => 'bad@example.com', 'roles' => ['owner']])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('roles');

        $this->patchJson(route('api.v1.users.update', $owner), ['name' => 'Nope'])->assertForbidden();
        $this->deleteJson(route('api.v1.users.destroy', $owner))->assertForbidden();
    }

    public function test_token_abilities_limit_the_api(): void
    {
        $admin = $this->userWithRole('admin');

        Sanctum::actingAs($admin, ['read']);

        $this->getJson(route('api.v1.roles.index'))->assertOk()->assertJsonCount(4, 'data');
        $this->getJson(route('api.v1.permissions.index'))->assertOk();
        $this->postJson(route('api.v1.users.store'), ['name' => 'X', 'email' => 'x@example.com'])->assertForbidden();
    }

    public function test_invitations_can_be_managed_through_the_api(): void
    {
        Notification::fake();
        $admin = $this->userWithRole('admin');

        Sanctum::actingAs($admin, ['read', 'write']);

        $id = $this->postJson(route('api.v1.invitations.store'), ['email' => 'Invited@Example.com', 'role' => 'editor'])
            ->assertCreated()
            ->assertJsonPath('data.email', 'invited@example.com')
            ->assertJsonPath('data.status', 'pending')
            ->json('data.id');

        $this->deleteJson(route('api.v1.invitations.destroy', $id))->assertOk()->assertJsonPath('data.status', 'revoked');
    }
}
