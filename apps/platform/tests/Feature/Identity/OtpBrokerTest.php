<?php

namespace Tests\Feature\Identity;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Starter\Identity\Models\LoginChallenge;
use Starter\Identity\Notifications\OneTimeCodeNotification;
use Starter\Identity\Otp\OtpBroker;
use Starter\Identity\Otp\OtpPurpose;
use Starter\Identity\Otp\OtpStatus;
use Tests\TestCase;

class OtpBrokerTest extends TestCase
{
    use RefreshDatabase;

    protected function issue(OtpPurpose $purpose = OtpPurpose::Login, string $email = 'a@example.com'): array
    {
        Notification::fake();

        $challenge = app(OtpBroker::class)->issue($purpose, $email);

        return [$challenge, $this->codeSentToEmail($email)];
    }

    public function test_the_verifier_is_bound_to_the_challenge_and_purpose(): void
    {
        [$challenge, $code] = $this->issue();
        $broker = app(OtpBroker::class);

        // The same code does not verify another purpose nor another challenge...
        $this->assertSame(OtpStatus::Invalid, $broker->verify($challenge->id, $code, OtpPurpose::Confirm)->status);

        $other = LoginChallenge::query()->create([
            ...collect($challenge->getAttributes())->except(['id', 'created_at', 'updated_at'])->all(),
            'email' => 'b@example.com',
        ]);
        $this->assertFalse($broker->verify($other->id, $code)->verified());

        $this->assertTrue($broker->verify($challenge->id, $code, OtpPurpose::Login)->verified());
    }

    public function test_a_challenge_is_consumed_only_once(): void
    {
        [$challenge, $code] = $this->issue();
        $broker = app(OtpBroker::class);

        $this->assertTrue($broker->verify($challenge->id, $code)->verified());
        $this->assertFalse($broker->verify($challenge->id, $code)->verified());
    }

    public function test_codes_are_not_sent_for_non_deliverable_challenges(): void
    {
        Notification::fake();

        $challenge = app(OtpBroker::class)->issue(OtpPurpose::Login, 'ghost@example.com', ['deliverable' => false]);

        Notification::assertNothingSent();
        $this->assertFalse($challenge->deliverable);
        $this->assertSame(OtpStatus::Invalid, app(OtpBroker::class)->verify($challenge->id, '123456')->status);
    }

    public function test_malformed_challenge_ids_are_rejected(): void
    {
        $this->assertSame(OtpStatus::Invalid, app(OtpBroker::class)->verify('not-a-uuid', '123456')->status);
    }

    public function test_expired_challenges_are_pruned(): void
    {
        [$challenge] = $this->issue();

        $this->travel(2)->days();
        $this->artisan('model:prune', ['--model' => [LoginChallenge::class]]);

        $this->assertNull(LoginChallenge::query()->find($challenge->id));
        Notification::assertSentOnDemandTimes(OneTimeCodeNotification::class, 1);
    }
}
