<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ErrorPagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_unknown_pages_render_the_not_found_page(): void
    {
        $this->get('/this-page-does-not-exist')
            ->assertNotFound()
            ->assertInertia(fn (Assert $page) => $page->component('errors/Error')->where('status', 404));
    }

    public function test_the_not_found_page_knows_the_user_and_locale(): void
    {
        $user = User::factory()->create(['locale' => 'es']);

        $this->actingAs($user)
            ->get('/missing-page')
            ->assertNotFound()
            ->assertInertia(fn (Assert $page) => $page
                ->component('errors/Error')
                ->where('auth.user.id', $user->id)
                ->where('locale', 'es'));
    }

    public function test_api_errors_stay_json(): void
    {
        $this->getJson('/api/v1/does-not-exist')->assertNotFound()->assertJsonStructure(['message']);
        $this->getJson(route('api.v1.me'))->assertUnauthorized();
    }

    public function test_requests_carry_a_correlation_id(): void
    {
        $response = $this->get(route('home'));

        $this->assertTrue(Str::isUuid((string) $response->headers->get('X-Request-Id')));

        $id = (string) Str::uuid();
        $this->withHeader('X-Request-Id', $id)->get(route('home'))->assertHeader('X-Request-Id', $id);
    }
}
