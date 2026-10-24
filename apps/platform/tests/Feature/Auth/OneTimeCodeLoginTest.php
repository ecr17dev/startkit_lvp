<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Starter\Administration\Models\AuditEvent;
use Starter\Identity\Models\LoginChallenge;
use Starter\Identity\Notifications\OneTimeCodeNotification;
use Tests\TestCase;

class OneTimeCodeLoginTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
    }

    public function test_login_screen_can_be_rendered(): void
    {
        $this->get(route('login'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('auth/Login')
                ->where('codeLength', 6)
                ->where('features.passwordLogin', false));
    }

    public function test_a_code_is_sent_to_active_users(): void
    {
        $user = User::factory()->create();

        $this->post(route('login.code'), ['email' => strtoupper($user->email)])
            ->assertRedirect(route('login.verify'));

        $code = $this->codeSentTo($user);
        $challenge = LoginChallenge::query()->sole();

        $this->assertMatchesRegularExpression('/^\d{6}$/', $code);
        $this->assertTrue($challenge->deliverable);
        $this->assertSame($user->email, $challenge->email);
        // Only an HMAC of the code is stored...
        $this->assertNotSame($code, $challenge->verifier);
        $this->assertStringNotContainsString($code, json_encode($challenge->getAttributes()) ?: '');

        $this->get(route('login.verify'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('auth/VerifyCode')
                ->where('email', $user->email)
                ->where('purpose', 'login'));
    }

    public function test_unknown_and_disabled_accounts_get_the_same_response_without_email(): void
    {
        $disabled = User::factory()->inactive()->create();

        foreach (['nobody@example.com', $disabled->email] as $email) {
            $this->post(route('login.code'), ['email' => $email])
                ->assertRedirect(route('login.verify'));

            $this->get(route('login.verify'))
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page->component('auth/VerifyCode')->where('email', $email));
        }

        Notification::assertNothingSent();
        $this->assertSame(2, LoginChallenge::query()->where('deliverable', false)->count());
    }

    public function test_users_can_log_in_with_a_valid_code(): void
    {
        $user = User::factory()->unverified()->create();

        $this->post(route('login.code'), ['email' => $user->email]);

        $this->post(route('login.verify.store'), ['code' => $this->codeSentTo($user)])
            ->assertRedirect(route('dashboard', absolute: false));

        $this->assertAuthenticatedAs($user);
        $this->assertNotNull($user->refresh()->email_verified_at);
        $this->assertNotNull(LoginChallenge::query()->sole()->consumed_at);
        $this->assertTrue(AuditEvent::query()->where('action', 'auth.login')->where('actor_id', $user->id)->exists());
    }

    public function test_a_code_can_only_be_used_once(): void
    {
        $user = User::factory()->create();

        $this->post(route('login.code'), ['email' => $user->email]);
        $code = $this->codeSentTo($user);
        $challengeId = LoginChallenge::query()->sole()->id;

        $this->post(route('login.verify.store'), ['code' => $code]);
        $this->post(route('logout'));

        $this->withSession(['auth_otp' => ['id' => $challengeId, 'purpose' => 'login']])
            ->post(route('login.verify.store'), ['code' => $code])
            ->assertSessionHasErrors('code');

        $this->assertGuest();
    }

    public function test_invalid_codes_are_rejected_and_attempts_are_limited(): void
    {
        $user = User::factory()->create();

        $this->post(route('login.code'), ['email' => $user->email]);
        $code = $this->codeSentTo($user);
        $wrong = $code === '000000' ? '111111' : '000000';

        for ($i = 0; $i < 5; $i++) {
            $this->post(route('login.verify.store'), ['code' => $wrong])->assertSessionHasErrors('code');
        }

        // The right code no longer works once the attempts are exhausted...
        $this->post(route('login.verify.store'), ['code' => $code])
            ->assertSessionHasErrors(['code' => __('Too many attempts. Request a new code.')]);

        $this->assertGuest();
        $this->assertSame(5, LoginChallenge::query()->sole()->attempts);
    }

    public function test_expired_codes_are_rejected(): void
    {
        $user = User::factory()->create();

        $this->post(route('login.code'), ['email' => $user->email]);
        $code = $this->codeSentTo($user);

        $this->travel(6)->minutes();

        $this->post(route('login.verify.store'), ['code' => $code])->assertSessionHasErrors('code');
        $this->assertGuest();
    }

    public function test_requesting_again_during_the_cooldown_reuses_the_challenge(): void
    {
        $user = User::factory()->create();

        $this->post(route('login.code'), ['email' => $user->email]);
        $this->post(route('login.code'), ['email' => $user->email]);

        Notification::assertSentToTimes($user, OneTimeCodeNotification::class, 1);
        $this->assertSame(1, LoginChallenge::query()->count());
    }

    public function test_resending_respects_the_cooldown_and_invalidates_the_previous_code(): void
    {
        $user = User::factory()->create();

        $this->post(route('login.code'), ['email' => $user->email]);
        $first = $this->codeSentTo($user);

        $this->post(route('login.verify.resend'))->assertSessionHasErrors('code');
        Notification::assertSentToTimes($user, OneTimeCodeNotification::class, 1);

        $this->travel(61)->seconds();

        $this->post(route('login.verify.resend'))->assertRedirect(route('login.verify'));
        Notification::assertSentToTimes($user, OneTimeCodeNotification::class, 2);
        $second = $this->codeSentTo($user);

        $this->assertNotNull(LoginChallenge::query()->oldest()->first()?->invalidated_at);

        if ($first !== $second) {
            $this->post(route('login.verify.store'), ['code' => $first])->assertSessionHasErrors('code');
        }

        $this->post(route('login.verify.store'), ['code' => $second])->assertRedirect();
        $this->assertAuthenticatedAs($user);
    }

    public function test_the_verify_screen_requires_a_pending_challenge(): void
    {
        $this->get(route('login.verify'))->assertRedirect(route('login'));
        $this->post(route('login.verify.store'), ['code' => '123456'])->assertRedirect(route('login'));
    }

    public function test_password_login_is_disabled_by_default(): void
    {
        $user = User::factory()->create();

        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])->assertNotFound();
        $this->get(route('password.request'))->assertNotFound();
        $this->assertGuest();
    }

    public function test_users_with_two_factor_pass_the_challenge_after_the_code(): void
    {
        $user = User::factory()->withTwoFactor()->create();

        $this->post(route('login.code'), ['email' => $user->email]);

        $this->post(route('login.verify.store'), ['code' => $this->codeSentTo($user)])
            ->assertRedirect(route('two-factor.login'))
            ->assertSessionHas('login.id', $user->id);

        $this->assertGuest();
    }

    public function test_code_requests_are_rate_limited(): void
    {
        for ($i = 0; $i < 6; $i++) {
            $this->post(route('login.code'), ['email' => "user{$i}@example.com"]);
        }

        $this->post(route('login.code'), ['email' => 'another@example.com'])->assertTooManyRequests();
    }
}
