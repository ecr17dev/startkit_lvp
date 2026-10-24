<?php

namespace Tests\Feature;

use App\Http\Controllers\UiKitController;
use App\Models\Project;
use App\Support\TableSort;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Support\SessionKey;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * UI kit catalog (/ui-kit) and the server side of the kit tables.
 */
class UiKitTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, mixed>
     */
    protected function wizard(array $overrides = []): array
    {
        return [
            'name' => 'Ana Pérez',
            'email' => 'ana@acme.cl',
            'plan' => 'team',
            'seats' => 5,
            'starts_on' => now()->toDateString(),
            'channels' => ['email'],
            'terms' => true,
            ...$overrides,
        ];
    }

    public function test_the_catalog_is_off_unless_the_feature_is_enabled(): void
    {
        config(['starter.features.ui_kit' => false]);
        $user = $this->userWithRole('reader');

        $this->actingAs($user)->get(route('ui-kit.index'))->assertNotFound();
        $this->actingAs($user)->get(route('ui-kit.show', 'tables'))->assertNotFound();
        $this->actingAs($user)->post(route('ui-kit.wizard'), $this->wizard())->assertNotFound();
        $this->actingAs($user)->get(route('dashboard'))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('features.uiKit', false));
    }

    public function test_signed_in_users_browse_every_section(): void
    {
        config(['starter.features.ui_kit' => true]);

        $this->get(route('ui-kit.index'))->assertRedirect(route('login'));

        $user = $this->userWithRole('reader');

        $this->actingAs($user)->get(route('ui-kit.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('ui-kit/Index')
                ->where('sections', UiKitController::SECTIONS)
                ->where('features.uiKit', true));

        foreach (UiKitController::SECTIONS as $section) {
            $this->actingAs($user)->get(route('ui-kit.show', $section))
                ->assertOk()
                ->assertInertia(fn (AssertableInertia $page) => $page->component('ui-kit/'.Str::studly($section)));
        }

        $this->actingAs($user)->get(route('ui-kit.show', 'unknown'))->assertNotFound();
    }

    public function test_the_demo_wizard_validates_on_the_server_and_saves_nothing(): void
    {
        config(['starter.features.ui_kit' => true]);
        $user = $this->userWithRole('reader');

        $this->actingAs($user)->from(route('ui-kit.show', 'wizard'))
            ->post(route('ui-kit.wizard'), $this->wizard(['email' => 'ana@example.com', 'terms' => false]))
            ->assertRedirect(route('ui-kit.show', 'wizard'))
            ->assertSessionHasErrors(['email', 'terms']);

        $this->actingAs($user)->post(route('ui-kit.wizard'), $this->wizard(['starts_on' => now()->subDay()->toDateString()]))
            ->assertSessionHasErrors('starts_on');

        $this->actingAs($user)->from(route('ui-kit.show', 'wizard'))
            ->post(route('ui-kit.wizard'), $this->wizard())
            ->assertRedirect(route('ui-kit.show', 'wizard'))
            ->assertSessionHasNoErrors();

        $this->assertSame('success', data_get(session(SessionKey::FLASH_DATA), 'toast.type'));
    }

    public function test_table_sort_only_orders_by_allowed_columns(): void
    {
        $this->assertSame(['nullable', 'string'], array_slice(TableSort::rules(['name'])['sort'], 0, 2));

        $sql = fn (array $filters) => TableSort::apply(Project::query(), $filters, ['name', 'created_at'], default: 'created_at')
            ->toRawSql();

        $this->assertStringContainsString('order by "name" asc, "id" asc', $sql(['sort' => 'name']));
        $this->assertStringContainsString('order by "name" desc, "id" desc', $sql(['sort' => 'name', 'direction' => 'desc']));
        // Unknown columns and directions fall back to the default order...
        $this->assertStringContainsString('order by "created_at" desc, "id" desc', $sql(['sort' => 'password; drop table users']));
        $this->assertStringContainsString('order by "name" asc', $sql(['sort' => 'name', 'direction' => 'sideways']));
        $this->assertStringContainsString('order by "id" desc', TableSort::apply(Project::query(), [], ['id'])->toRawSql());
        $this->assertStringNotContainsString('"id" desc, "id"', TableSort::apply(Project::query(), [], ['id'])->toRawSql());
    }
}
