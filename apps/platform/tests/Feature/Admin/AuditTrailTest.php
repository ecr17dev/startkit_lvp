<?php

namespace Tests\Feature\Admin;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Starter\Administration\Models\AuditEvent;
use Tests\TestCase;

class AuditTrailTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_audit_trail_requires_permission(): void
    {
        $editor = $this->userWithRole('editor');

        $this->actingAs($editor)->get(route('admin.audit.index'))->assertForbidden();
    }

    public function test_events_can_be_filtered(): void
    {
        $admin = $this->userWithRole('admin');

        audit('roles.created', null, ['name' => 'x'], $admin);
        audit('users.created', null, [], $admin);

        $this->actingAs($admin)
            ->get(route('admin.audit.index', ['action' => 'roles']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/audit/Index')
                ->has('events.data', 1)
                ->where('events.data.0.action', 'roles.created')
                ->where('events.data.0.actor.id', $admin->id));
    }

    public function test_secrets_are_never_written_to_the_audit_trail(): void
    {
        $event = audit('settings.updated', null, ['password' => 'p4ss', 'nested' => ['api_key' => 'sk-123', 'host' => 'smtp']]);

        $this->assertInstanceOf(AuditEvent::class, $event);
        $this->assertSame('[redacted]', $event->properties['password'] ?? null);
        $this->assertSame('[redacted]', $event->properties['nested']['api_key'] ?? null);
        $this->assertSame('smtp', $event->properties['nested']['host'] ?? null);
    }
}
