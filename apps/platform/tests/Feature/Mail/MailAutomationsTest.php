<?php

namespace Tests\Feature\Mail;

use App\Jobs\DeliverMail;
use App\Mail\Automation\MailAutomations;
use App\Models\MailAutomation;
use App\Models\MailDelivery;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use RuntimeException;
use Starter\Administration\Mail\TemplateMail;
use Starter\Administration\Models\AuditEvent;
use Tests\TestCase;

class MailAutomationsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<string, mixed>  $settings
     */
    private function automation(string $trigger, array $settings = [], string $template = 'automation.welcome', bool $active = true): MailAutomation
    {
        return MailAutomation::query()->create([
            'name' => ucfirst($trigger),
            'trigger' => $trigger,
            'settings' => $settings,
            'template_key' => $template,
            'is_active' => $active,
        ]);
    }

    private function engine(): MailAutomations
    {
        $engine = app(MailAutomations::class);
        $engine->flush();

        return $engine;
    }

    public function test_the_welcome_is_queued_with_its_delay_and_sent_once(): void
    {
        Queue::fake();
        $this->automation('user_created', ['delay_minutes' => 10]);

        $user = User::factory()->create(['email' => 'ana@acme.test']);

        $delivery = MailDelivery::query()->sole();
        $this->assertSame('pending', $delivery->status);
        $this->assertSame($user->id, $delivery->user_id);
        $this->assertEqualsWithDelta(now()->addMinutes(10)->timestamp, $delivery->send_at?->timestamp, 5);
        Queue::assertPushedOn('mail', DeliverMail::class, fn (DeliverMail $job) => $job->deliveryId === $delivery->id && $job->delay !== null);

        // Sending it renders the template for the recipient...
        Mail::fake();
        (new DeliverMail($delivery->id))->handle();

        Mail::assertSent(TemplateMail::class, fn (TemplateMail $mail) => $mail->hasTo('ana@acme.test')
            && $mail->templateKey === 'automation.welcome'
            && $mail->variables['recipient_name'] === $user->name);
        $this->assertSame('sent', $delivery->refresh()->status);
        $this->assertNotNull($delivery->sent_at);
        $this->assertNotEmpty($delivery->subject);

        // A second run does nothing...
        (new DeliverMail($delivery->id))->handle();
        Mail::assertSentCount(1);
    }

    public function test_paused_automations_do_not_send(): void
    {
        Queue::fake();
        $this->automation('user_created', [], active: false);

        User::factory()->create();

        $this->assertSame(0, MailDelivery::query()->count());
    }

    public function test_inactivity_mails_each_inactive_period_once(): void
    {
        Queue::fake();
        $now = CarbonImmutable::parse('2026-10-01 12:00:00');
        $this->travelTo($now);

        $automation = $this->automation('inactivity', ['days' => 30], 'automation.inactivity');
        $inactive = User::factory()->create(['last_login_at' => $now->subDays(40)]);
        User::factory()->create(['last_login_at' => $now->subDays(5)]);
        User::factory()->create(['last_login_at' => $now->subDays(60), 'is_active' => false]);

        $this->assertSame(['inactivity' => 1, 'scheduled' => 0], $this->engine()->runDue());
        $delivery = MailDelivery::query()->sole();
        $this->assertSame($inactive->id, $delivery->user_id);
        $this->assertSame(40, $delivery->variables['days_inactive'] ?? null);

        // Running again does not repeat it...
        $this->travelTo($now->addHour());
        $this->assertSame(0, $this->engine()->runDue()['inactivity']);

        // After a new login and another inactive period, it goes again (the
        // user who logged in 5 days before the start is now inactive too)...
        $inactive->forceFill(['last_login_at' => $now->addDays(1)])->save();
        $this->travelTo($now->addDays(32));
        $this->assertSame(2, $this->engine()->runDue()['inactivity']);
        $this->assertSame(2, MailDelivery::query()->where('automation_id', $automation->id)->where('user_id', $inactive->id)->count());
    }

    public function test_inactivity_can_be_limited_to_a_role(): void
    {
        Queue::fake();
        $this->seedPermissions();
        $editor = $this->userWithRole('editor', ['last_login_at' => now()->subDays(40)]);
        $this->userWithRole('reader', ['last_login_at' => now()->subDays(40)]);

        $this->automation('inactivity', ['days' => 30, 'role' => 'editor'], 'automation.inactivity');
        $this->engine()->runDue();

        $this->assertSame([$editor->id], MailDelivery::query()->pluck('user_id')->all());
    }

    public function test_weekly_sends_go_once_per_occurrence_in_the_time_zone(): void
    {
        Queue::fake();
        // Monday 2026-10-05 08:00 in Santiago (UTC-3)...
        $this->travelTo(CarbonImmutable::parse('2026-10-05 11:00:00', 'UTC'));
        $this->seedPermissions();
        $admins = [$this->userWithRole('admin'), $this->userWithRole('admin')];
        $this->userWithRole('reader');

        $automation = $this->automation('scheduled', ['frequency' => 'weekly', 'weekday' => 1, 'time' => '09:00', 'timezone' => 'America/Santiago', 'role' => 'admin'], 'automation.announcement');

        // 08:30 local: not yet...
        $this->travelTo(CarbonImmutable::parse('2026-10-05 11:30:00', 'UTC'));
        $this->assertSame(0, $this->engine()->runDue()['scheduled']);

        // 09:05 local: to every admin...
        $this->travelTo(CarbonImmutable::parse('2026-10-05 12:05:00', 'UTC'));
        $this->assertSame(2, $this->engine()->runDue()['scheduled']);
        $this->assertEqualsCanonicalizing(array_map(fn (User $user) => $user->id, $admins), MailDelivery::query()->pluck('user_id')->all());
        $this->assertSame('2026-10-05 12:00:00', $automation->refresh()->last_run_at?->utc()->format('Y-m-d H:i:s'));

        // Later the same day: already sent...
        $this->travelTo(CarbonImmutable::parse('2026-10-05 15:00:00', 'UTC'));
        $this->assertSame(0, $this->engine()->runDue()['scheduled']);

        // Next Monday: again...
        $this->travelTo(CarbonImmutable::parse('2026-10-12 12:01:00', 'UTC'));
        $this->assertSame(2, $this->engine()->runDue()['scheduled']);
    }

    public function test_a_one_time_send_in_the_past_is_not_sent(): void
    {
        Queue::fake();
        User::factory()->create();

        $this->automation('scheduled', ['frequency' => 'once', 'date' => now()->subDays(3)->toDateString(), 'time' => '09:00'], 'automation.announcement');

        $this->assertSame(0, $this->engine()->runDue()['scheduled']);
    }

    public function test_system_events_mail_the_person_involved_or_a_role(): void
    {
        Queue::fake();
        $this->seedPermissions();
        $admin = $this->userWithRole('admin');
        $user = User::factory()->create();

        $this->automation('event', ['event' => 'users.disabled', 'recipients' => 'person'], 'automation.event');
        $this->automation('event', ['event' => 'users.roles_changed', 'recipients' => 'role', 'role' => 'admin'], 'automation.event');

        audit('users.disabled', $user, [], $admin);
        audit('users.updated', $user, ['roles' => ['from' => ['reader'], 'to' => ['editor']]], $admin);
        audit('users.updated', $user, ['changed' => ['name']], $admin);

        $deliveries = MailDelivery::query()->orderBy('id')->get();
        $this->assertCount(2, $deliveries);
        $this->assertSame($user->id, $deliveries[0]->user_id);
        $this->assertSame($admin->name, $deliveries[0]->variables['actor_name'] ?? null);
        $this->assertSame($admin->id, $deliveries[1]->user_id);
        $this->assertStringContainsString('reader → editor', (string) ($deliveries[1]->variables['event_details'] ?? ''));
    }

    public function test_deliveries_to_deactivated_users_are_skipped_and_failures_are_kept(): void
    {
        Queue::fake();
        $automation = $this->automation('user_created');
        $user = User::factory()->create();
        $delivery = MailDelivery::query()->sole();

        $user->forceFill(['is_active' => false])->save();
        (new DeliverMail($delivery->id))->handle();
        $this->assertSame('skipped', $delivery->refresh()->status);

        $other = $automation->deliveries()->create(['template_key' => 'automation.welcome', 'email' => 'x@acme.test', 'status' => 'pending']);
        (new DeliverMail($other->id))->failed(new RuntimeException('API key is invalid'));
        $this->assertSame('failed', $other->refresh()->status);
        $this->assertSame('API key is invalid', $other->error);
    }

    public function test_automations_are_saved_with_the_settings_of_their_trigger(): void
    {
        $admin = $this->userWithRole('admin');

        $this->actingAs($admin)
            ->post(route('admin.mail.automations.store'), [
                'name' => 'Anuncio mensual',
                'trigger' => 'scheduled',
                'template_key' => 'automation.announcement',
                'is_active' => true,
                'settings' => ['frequency' => 'monthly', 'day' => '5', 'weekday' => 3, 'date' => '2026-12-01', 'time' => '10:30', 'timezone' => 'America/Santiago', 'role' => null, 'days' => 40, 'event' => 'users.created'],
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('admin.mail.automations.index'));

        $automation = MailAutomation::query()->sole();
        $settings = $automation->settings;
        ksort($settings);
        $this->assertSame(['day' => 5, 'frequency' => 'monthly', 'time' => '10:30', 'timezone' => 'America/Santiago'], $settings);
        $this->assertTrue(AuditEvent::query()->where('action', 'mail.automation_saved')->exists());
    }

    public function test_invalid_automations_are_rejected(): void
    {
        $admin = $this->userWithRole('admin');
        $base = ['name' => 'X', 'template_key' => 'automation.event'];

        $this->actingAs($admin)->post(route('admin.mail.automations.store'), [...$base, 'trigger' => 'event', 'settings' => ['event' => 'ai.budget.threshold', 'recipients' => 'person']])
            ->assertSessionHasErrors(['settings.recipients']);
        $this->actingAs($admin)->post(route('admin.mail.automations.store'), [...$base, 'trigger' => 'event', 'settings' => ['event' => 'users.created', 'recipients' => 'role']])
            ->assertSessionHasErrors(['settings.role']);
        $this->actingAs($admin)->post(route('admin.mail.automations.store'), [...$base, 'trigger' => 'scheduled', 'settings' => ['frequency' => 'once', 'time' => '09:00']])
            ->assertSessionHasErrors(['settings.date']);
        $this->actingAs($admin)->post(route('admin.mail.automations.store'), [...$base, 'trigger' => 'inactivity', 'settings' => ['days' => 0, 'role' => 'nope']])
            ->assertSessionHasErrors(['settings.days', 'settings.role']);
        $this->actingAs($admin)->post(route('admin.mail.automations.store'), [...$base, 'template_key' => 'custom.nope', 'trigger' => 'user_created'])
            ->assertSessionHasErrors(['template_key']);

        $this->assertSame(0, MailAutomation::query()->count());
    }

    public function test_the_pages_pause_delete_and_retry(): void
    {
        Queue::fake();
        $admin = $this->userWithRole('admin');
        $automation = $this->automation('user_created');
        User::factory()->create(['email' => 'nuevo@acme.test']);
        $failed = $automation->deliveries()->create(['template_key' => 'automation.welcome', 'email' => 'malo@acme.test', 'status' => 'failed', 'error' => 'Domain not verified']);

        $this->actingAs($admin)
            ->get(route('admin.mail.automations.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/mail/automations/Index')
                ->where('automations.0.counts.pending', 1)
                ->where('automations.0.counts.failed', 1)
                ->has('events', count(MailAutomations::EVENTS)));

        $this->actingAs($admin)
            ->get(route('admin.mail.deliveries.index', ['status' => 'failed']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/mail/Deliveries')
                ->has('deliveries.data', 1)
                ->where('deliveries.data.0.error', 'Domain not verified'));

        $this->actingAs($admin)->post(route('admin.mail.deliveries.retry', $failed))->assertRedirect();
        $this->assertSame('pending', $failed->refresh()->status);
        Queue::assertPushed(DeliverMail::class, fn (DeliverMail $job) => $job->deliveryId === $failed->id);

        $this->actingAs($admin)->patch(route('admin.mail.automations.toggle', $automation))->assertRedirect();
        $this->assertFalse($automation->refresh()->is_active);

        $this->actingAs($admin)->delete(route('admin.mail.automations.destroy', $automation))->assertRedirect(route('admin.mail.automations.index'));
        $this->assertSame(0, MailAutomation::query()->count());
        $this->assertSame(['skipped'], MailDelivery::query()->distinct()->pluck('status')->all());
    }

    public function test_viewers_cannot_change_automations(): void
    {
        $viewer = $this->userWithRole('reader');
        $viewer->givePermissionTo('mail.read');
        $automation = $this->automation('user_created');

        $this->actingAs($viewer)->get(route('admin.mail.automations.index'))->assertOk();
        $this->actingAs($viewer)->get(route('admin.mail.deliveries.index'))->assertOk();
        $this->actingAs($viewer)->get(route('admin.mail.automations.create'))->assertForbidden();
        $this->actingAs($viewer)->patch(route('admin.mail.automations.toggle', $automation))->assertForbidden();
        $this->actingAs($viewer)->delete(route('admin.mail.automations.destroy', $automation))->assertForbidden();
    }

    public function test_the_command_is_scheduled_every_five_minutes(): void
    {
        $event = collect(app(Schedule::class)->events())->first(fn ($event) => str_contains((string) $event->command, 'starter:mail-automations'));

        $this->assertNotNull($event);
        $this->assertSame('*/5 * * * *', $event->expression);

        $this->artisan('starter:mail-automations')->assertSuccessful();
    }
}
