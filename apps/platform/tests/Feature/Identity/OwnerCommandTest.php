<?php

namespace Tests\Feature\Identity;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Starter\Administration\Models\AuditEvent;
use Starter\Identity\Permissions;
use Tests\TestCase;

class OwnerCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_owner_is_created_from_the_cli_with_an_unverified_email(): void
    {
        $this->artisan('starter:owner', ['email' => 'Boss@Example.com', '--name' => 'Boss'])->assertSuccessful();

        $owner = User::query()->where('email', 'boss@example.com')->sole();

        $this->assertTrue($owner->isOwner());
        $this->assertSame('Boss', $owner->name);
        $this->assertNull($owner->email_verified_at);
        $this->assertNull($owner->password);
        $this->assertCount(count(Permissions::all()), $owner->permissionNames());

        $event = AuditEvent::query()->where('action', 'owner.created')->sole();
        $this->assertSame('cli', $event->channel);
    }

    public function test_existing_users_can_be_promoted(): void
    {
        $user = User::factory()->create();

        $this->artisan('starter:owner', ['email' => $user->email])->assertSuccessful();

        $this->assertTrue($user->refresh()->isOwner());
        $this->assertSame(1, User::query()->count());
        $this->assertDatabaseHas('audit_events', ['action' => 'owner.promoted']);
    }

    public function test_invalid_emails_are_rejected(): void
    {
        $this->artisan('starter:owner', ['email' => 'not-an-email'])->assertFailed();

        $this->assertSame(0, User::query()->count());
    }

    public function test_permissions_can_be_synced_from_the_cli(): void
    {
        $this->artisan('starter:permissions')->assertSuccessful();

        $this->assertDatabaseHas('roles', ['name' => 'owner']);
        $this->assertDatabaseHas('permissions', ['name' => 'rag.playground.use']);
    }
}
