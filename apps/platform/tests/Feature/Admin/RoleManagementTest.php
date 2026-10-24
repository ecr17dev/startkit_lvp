<?php

namespace Tests\Feature\Admin;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Starter\Identity\Permissions;
use Tests\TestCase;

class RoleManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_system_roles_are_created_with_the_catalog(): void
    {
        $this->seedPermissions();

        $this->assertSame(Permissions::systemRoles(), Role::query()->orderBy('id')->pluck('name')->all());
        $this->assertCount(count(Permissions::all()), Role::findByName('owner')->permissions);
        $this->assertFalse(Role::findByName('admin')->hasPermissionTo('permissions.manage'));
        $this->assertTrue(Role::findByName('reader')->hasPermissionTo('ai.chat'));
        $this->assertFalse(Role::findByName('reader')->hasPermissionTo('users.read'));
    }

    public function test_administrators_create_roles_only_with_permissions_they_hold(): void
    {
        $admin = $this->userWithRole('admin');

        $this->actingAs($admin)
            ->post(route('admin.roles.store'), ['name' => 'Support Team', 'permissions' => ['users.read', 'ai.chat']])
            ->assertRedirect(route('admin.roles.index'));

        $role = Role::findByName('support-team');
        $this->assertEqualsCanonicalizing(['users.read', 'ai.chat'], $role->permissions->pluck('name')->all());
        $this->assertDatabaseHas('audit_events', ['action' => 'roles.created', 'actor_id' => $admin->id]);

        $this->actingAs($admin)
            ->post(route('admin.roles.store'), ['name' => 'sneaky', 'permissions' => ['permissions.manage']])
            ->assertSessionHasErrors('permissions');
    }

    public function test_the_owner_role_is_immutable_and_system_roles_are_protected(): void
    {
        $owner = $this->userWithRole('owner');
        $ownerRole = Role::findByName('owner');
        $editor = Role::findByName('editor');

        $this->actingAs($owner)->get(route('admin.roles.edit', $ownerRole))->assertForbidden();
        $this->actingAs($owner)->put(route('admin.roles.update', $ownerRole), ['name' => 'owner', 'permissions' => []])->assertForbidden();
        $this->actingAs($owner)->delete(route('admin.roles.destroy', $editor))->assertForbidden();

        $this->actingAs($owner)
            ->put(route('admin.roles.update', $editor), ['name' => 'writers', 'permissions' => ['ai.chat']])
            ->assertSessionHasErrors('name');
    }

    public function test_custom_roles_can_be_updated_and_deleted(): void
    {
        $owner = $this->userWithRole('owner');
        $role = Role::create(['name' => 'temp', 'guard_name' => 'web']);

        $this->actingAs($owner)
            ->put(route('admin.roles.update', $role), ['name' => 'temporary', 'permissions' => ['ai.chat']])
            ->assertRedirect(route('admin.roles.index'));

        $this->assertSame('temporary', $role->refresh()->name);

        $this->actingAs($owner)->delete(route('admin.roles.destroy', $role))->assertRedirect(route('admin.roles.index'));
        $this->assertNull(Role::query()->find($role->id));
    }

    public function test_the_permission_matrix_requires_the_matrix_permissions(): void
    {
        $admin = $this->userWithRole('admin');
        $owner = $this->userWithRole('owner');
        $reader = Role::findByName('reader');

        $this->actingAs($admin)
            ->get(route('admin.permissions.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/permissions/Index')
                ->has('roles', 4)
                ->where('editable', []));

        $this->actingAs($admin)
            ->put(route('admin.permissions.update', $reader), ['permissions' => ['ai.chat', 'users.read']])
            ->assertForbidden();

        $this->actingAs($owner)
            ->put(route('admin.permissions.update', $reader), ['permissions' => ['ai.chat', 'users.read']])
            ->assertSessionHasNoErrors();

        $this->assertTrue($reader->refresh()->hasPermissionTo('users.read'));
        $this->assertDatabaseHas('audit_events', ['action' => 'roles.updated', 'actor_id' => $owner->id]);
    }
}
