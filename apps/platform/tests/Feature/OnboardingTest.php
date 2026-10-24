<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class OnboardingTest extends TestCase
{
    use RefreshDatabase;

    protected function owner(array $attributes = []): User
    {
        $this->seedPermissions();

        $owner = User::factory()->create($attributes);
        $owner->assignRole('owner');

        return $owner;
    }

    public function test_the_owner_completes_the_workspace_setup_first(): void
    {
        $owner = $this->owner(['name' => '']);

        $this->actingAs($owner)->get(route('dashboard'))->assertRedirect(route('onboarding.show'));

        $this->actingAs($owner)
            ->get(route('onboarding.show'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('onboarding/Index')->whereNot('workspace', null));

        $this->actingAs($owner)
            ->post(route('onboarding.store'), [
                'name' => 'Owner',
                'locale' => 'en',
                'app_name' => 'Acme Portal',
                'workspace_locale' => 'es',
                'timezone' => 'America/Santiago',
                'registration' => 'closed',
            ])
            ->assertRedirect(route('dashboard'));

        $this->assertSame('Owner', $owner->refresh()->name);
        $this->assertSame('Acme Portal', settings('branding.app_name'));
        $this->assertSame('closed', settings('auth.registration'));
        $this->assertTrue((bool) settings('onboarding.completed'));

        $this->actingAs($owner)->get(route('dashboard'))->assertOk();
    }

    public function test_users_without_a_name_complete_their_profile(): void
    {
        $this->seedPermissions();
        $user = User::factory()->create(['name' => '']);
        $user->assignRole('reader');

        $this->actingAs($user)->get(route('dashboard'))->assertRedirect(route('onboarding.show'));

        $this->actingAs($user)
            ->get(route('onboarding.show'))
            ->assertInertia(fn (Assert $page) => $page->where('workspace', null));

        $this->actingAs($user)
            ->post(route('onboarding.store'), ['name' => 'Reader', 'locale' => 'es', 'app_name' => 'Hijack'])
            ->assertRedirect(route('dashboard'));

        $this->assertSame('Reader', $user->refresh()->name);
        $this->assertNotSame('Hijack', settings('branding.app_name'));
    }
}
