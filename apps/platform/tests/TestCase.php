<?php

namespace Tests;

use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Notification;
use Laravel\Fortify\Features;
use Starter\Identity\Authorization\PermissionSynchronizer;
use Starter\Identity\Notifications\OneTimeCodeNotification;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Pages are rendered without a frontend build...
        $this->withoutVite();
    }

    /**
     * Allow the password based flows (disabled by default: STARTER_AUTH_METHOD=email-otp).
     */
    protected function enablePasswordLogin(): void
    {
        config(['starter.auth.method' => 'email-otp+password']);
    }

    /**
     * Create the permission catalog and the system roles.
     */
    protected function seedPermissions(): void
    {
        app(PermissionSynchronizer::class)->sync();
    }

    /**
     * Create a user with the given roles (the catalog is synced first).
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function userWithRole(string|array $roles, array $attributes = []): User
    {
        $this->seedPermissions();

        $user = User::factory()->create($attributes);
        $user->assignRole($roles);

        // Owners are sent to the onboarding until the workspace is set up...
        if ($user->isOwner()) {
            settings()->set(['onboarding.completed' => true]);
        }

        return $user->refresh();
    }

    /**
     * Get the last one-time code sent to a user (Notification::fake required).
     */
    protected function codeSentTo(User $user): string
    {
        $code = null;

        Notification::assertSentTo($user, OneTimeCodeNotification::class, function (OneTimeCodeNotification $notification) use (&$code) {
            $code = $notification->code;

            return true;
        });

        return (string) $code;
    }

    /**
     * Get the last one-time code sent to an email without account (Notification::fake required).
     */
    protected function codeSentToEmail(string $email): string
    {
        $code = null;

        Notification::assertSentOnDemand(OneTimeCodeNotification::class, function (OneTimeCodeNotification $notification, array $channels, object $notifiable) use ($email, &$code) {
            if (($notifiable->routes['mail'] ?? null) === $email) {
                $code = $notification->code;
            }

            return true;
        });

        return (string) $code;
    }

    protected function skipUnlessFortifyHas(string $feature, ?string $message = null): void
    {
        if (! Features::enabled($feature)) {
            $this->markTestSkipped($message ?? "Fortify feature [{$feature}] is not enabled.");
        }
    }
}
