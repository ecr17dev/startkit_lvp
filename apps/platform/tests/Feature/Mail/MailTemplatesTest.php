<?php

namespace Tests\Feature\Mail;

use App\Ai\Mail\MailTemplateWriter;
use App\Models\MailAutomation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;
use Starter\Administration\Mail\MailTemplates;
use Starter\Administration\Mail\RenderedMailable;
use Starter\Administration\Models\AuditEvent;
use Starter\Administration\Models\MailTemplate;
use Starter\Ai\Models\AiRequest;
use Starter\Identity\Notifications\OneTimeCodeNotification;
use Starter\Identity\Otp\OtpPurpose;
use Tests\Concerns\CreatesAiCatalog;
use Tests\TestCase;

class MailTemplatesTest extends TestCase
{
    use CreatesAiCatalog, RefreshDatabase;

    private function templates(): MailTemplates
    {
        return app(MailTemplates::class);
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function payload(array $values = []): array
    {
        return [
            'locale' => 'es',
            'subject' => 'Tu código para {{ app_name }}',
            'body' => "# Hola {{ recipient_name }}\n\nTu código es **{{ code }}**.\n\n[[Entrar|{{ login_url }}]]",
            'format' => 'markdown',
            ...$values,
        ];
    }

    public function test_the_defaults_cover_access_emails_in_every_language(): void
    {
        foreach (['auth.login_code', 'auth.register_code', 'auth.invitation_code', 'auth.confirm_code', 'auth.invitation', 'auth.password_reset', 'mail.test', 'automation.welcome', 'automation.inactivity', 'automation.announcement', 'automation.event', 'ai.budget_threshold'] as $key) {
            $this->assertNotNull($this->templates()->definition($key), $key);
        }

        $spanish = $this->templates()->render('auth.login_code', ['code' => '483920', 'expires_minutes' => 5], 'es');
        $english = $this->templates()->render('auth.login_code', ['code' => '483920', 'expires_minutes' => 5], 'en');

        $this->assertStringContainsString('483920', $spanish->html);
        $this->assertStringContainsString('483920', $spanish->text);
        $this->assertNotSame($spanish->subject, $english->subject);
        $this->assertStringContainsString('5', $spanish->text);
    }

    public function test_a_customized_template_is_used_by_the_notification(): void
    {
        $admin = $this->userWithRole('admin');

        $this->actingAs($admin)
            ->put(route('admin.mail.templates.update', 'auth.login_code'), $this->payload())
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('admin.mail.templates.edit', ['key' => 'auth.login_code', 'locale' => 'es']));

        $user = User::factory()->create(['name' => 'Ana', 'locale' => 'es']);
        app()->setLocale('es');
        $message = (new OneTimeCodeNotification('112233', OtpPurpose::Login, 5))->toMail($user);
        $html = (string) $message->render();

        $this->assertSame('Tu código para '.config('app.name'), $message->subject);
        $this->assertStringContainsString('Hola Ana', $html);
        $this->assertMatchesRegularExpression('#<strong[^>]*>112233</strong>#', $html);
        $this->assertStringContainsString('class="button button-primary"', $html);

        // Other languages keep their default...
        $this->assertNotSame('Tu código para '.config('app.name'), $this->templates()->render('auth.login_code', ['code' => '1'], 'en')->subject);

        $this->assertTrue(AuditEvent::query()->where('action', 'mail.template_saved')->exists());
    }

    public function test_values_are_escaped_and_unknown_placeholders_stay_visible(): void
    {
        $rendered = $this->templates()->compose('{{ name }} {{ missing }}', 'Hola {{ name }} {{ missing }}', 'markdown', ['name' => '<script>alert(1)</script>']);

        $this->assertStringNotContainsString('<script>alert(1)</script>', $rendered->html);
        $this->assertStringContainsString('&lt;script&gt;', $rendered->html);
        $this->assertStringContainsString('{{ missing }}', $rendered->html);
        // The subject is plain text (the mailer escapes it)...
        $this->assertSame('<script>alert(1)</script> {{ missing }}', $rendered->subject);
    }

    public function test_buttons_only_allow_web_and_mail_links(): void
    {
        $rendered = $this->templates()->compose('x', "[[Ok|https://example.com/a?b=1&c=2]]\n\n[[Bad|javascript:alert(1)]]", 'markdown', []);

        $this->assertStringContainsString('href="https://example.com/a?b=1&amp;c=2"', $rendered->html);
        $this->assertStringNotContainsString('javascript:', $rendered->html);
        $this->assertStringContainsString('Ok: https://example.com/a?b=1&c=2', $rendered->text);
    }

    public function test_unknown_variables_are_rejected(): void
    {
        $this->actingAs($this->userWithRole('admin'))
            ->put(route('admin.mail.templates.update', 'auth.login_code'), $this->payload(['body' => 'Hola {{ nombre }} {{ code }}']))
            ->assertSessionHasErrors(['body']);

        $this->assertSame(0, MailTemplate::query()->count());
    }

    public function test_html_templates_lose_scripts_handlers_and_javascript_links(): void
    {
        $this->actingAs($this->userWithRole('admin'))
            ->put(route('admin.mail.templates.update', 'automation.welcome'), $this->payload([
                'format' => 'html',
                'body' => '<h1 onclick="steal()">Hola {{ recipient_name }}</h1><script>alert(1)</script><iframe src="https://x.test"></iframe><a href="javascript:alert(1)">x</a><a href="{{ login_url }}">Entrar</a>',
            ]))
            ->assertSessionHasNoErrors();

        $body = MailTemplate::query()->where('key', 'automation.welcome')->value('body');

        $this->assertStringNotContainsString('<script', (string) $body);
        $this->assertStringNotContainsString('onclick', (string) $body);
        $this->assertStringNotContainsString('<iframe', (string) $body);
        $this->assertStringNotContainsString('javascript:', (string) $body);
        $this->assertStringContainsString('<a href="{{ login_url }}">Entrar</a>', (string) $body);

        $rendered = $this->templates()->render('automation.welcome', ['recipient_name' => 'Ana'], 'es');
        $this->assertStringContainsString('<h1>Hola Ana</h1>', $rendered->html);
        $this->assertStringContainsString('Entrar ('.url('/login').')', $rendered->text);
    }

    public function test_restoring_the_default_removes_only_that_language(): void
    {
        $admin = $this->userWithRole('admin');
        $this->actingAs($admin)->put(route('admin.mail.templates.update', 'auth.login_code'), $this->payload())->assertSessionHasNoErrors();
        $this->actingAs($admin)->put(route('admin.mail.templates.update', 'auth.login_code'), $this->payload(['locale' => 'en', 'subject' => 'Code']))->assertSessionHasNoErrors();

        $this->actingAs($admin)
            ->delete(route('admin.mail.templates.destroy', 'auth.login_code'), ['locale' => 'es'])
            ->assertRedirect();

        $this->assertSame(['en'], MailTemplate::query()->where('key', 'auth.login_code')->pluck('locale')->all());
        $this->assertTrue(AuditEvent::query()->where('action', 'mail.template_reset')->exists());
    }

    public function test_custom_templates_are_created_and_cannot_be_deleted_while_an_automation_uses_them(): void
    {
        $admin = $this->userWithRole('admin');

        $this->actingAs($admin)
            ->post(route('admin.mail.templates.store'), ['name' => 'Boletín de octubre', 'locale' => 'es'])
            ->assertRedirect(route('admin.mail.templates.edit', ['key' => 'custom.boletin-de-octubre', 'locale' => 'es']));

        $template = MailTemplate::query()->where('key', 'custom.boletin-de-octubre')->sole();
        $this->assertSame('Boletín de octubre', $template->name);
        $this->assertTrue($this->templates()->exists('custom.boletin-de-octubre'));

        $automation = MailAutomation::query()->create(['name' => 'Boletín', 'trigger' => 'scheduled', 'settings' => ['frequency' => 'monthly', 'day' => 1, 'time' => '09:00'], 'template_key' => $template->key]);

        $this->actingAs($admin)
            ->delete(route('admin.mail.templates.destroy', $template->key))
            ->assertSessionHasErrors(['template']);
        $this->assertTrue(MailTemplate::query()->whereKey($template->id)->exists());

        $automation->delete();

        $this->actingAs($admin)
            ->delete(route('admin.mail.templates.destroy', $template->key))
            ->assertRedirect(route('admin.mail.templates.index'));
        $this->assertFalse(MailTemplate::query()->whereKey($template->id)->exists());
    }

    public function test_the_pages_list_templates_and_open_the_editor(): void
    {
        $admin = $this->userWithRole('admin');

        $this->actingAs($admin)
            ->get(route('admin.mail.templates.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/mail/templates/Index')
                ->has('templates', count($this->templates()->definitions()))
                ->where('locales.es', 'Español'));

        $this->actingAs($admin)
            ->get(route('admin.mail.templates.edit', ['key' => 'auth.invitation', 'locale' => 'en']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/mail/templates/Edit')
                ->where('template.locale', 'en')
                ->where('template.customized', false)
                ->where('template.format', 'markdown')
                ->where('usedBy', [])
                ->has('variables', fn (Assert $variables) => $variables->each(fn (Assert $variable) => $variable->hasAll(['name', 'description', 'sample']))));

        $this->actingAs($admin)->get(route('admin.mail.templates.edit', 'nope'))->assertNotFound();
    }

    public function test_the_preview_renders_with_sample_values(): void
    {
        $this->actingAs($this->userWithRole('admin'))
            ->postJson(route('admin.mail.templates.preview', 'auth.login_code'), [
                'subject' => 'Código {{ code }}',
                'body' => 'Hola {{ recipient_name }} {{ nada }}',
                'format' => 'markdown',
            ])
            ->assertOk()
            ->assertJsonPath('subject', 'Código 483920')
            ->assertJsonPath('unknown', ['nada'])
            ->assertJson(fn ($json) => $json->where('html', fn (string $html) => str_contains($html, 'Ana Pérez'))->etc());
    }

    public function test_permissions_protect_the_templates(): void
    {
        $reader = $this->userWithRole('reader');

        $this->actingAs($reader)->get(route('admin.mail.templates.index'))->assertForbidden();
        $this->actingAs($reader)->put(route('admin.mail.templates.update', 'auth.login_code'), $this->payload())->assertForbidden();

        $viewer = $this->userWithRole('reader');
        $viewer->givePermissionTo('mail.read');

        $this->actingAs($viewer)->get(route('admin.mail.templates.index'))->assertOk();
        $this->actingAs($viewer)->put(route('admin.mail.templates.update', 'auth.login_code'), $this->payload())->assertForbidden();
        $this->actingAs($viewer)->post(route('admin.mail.templates.generate', 'auth.login_code'), ['description' => 'Un correo de prueba largo', 'format' => 'markdown', 'locale' => 'es'])->assertForbidden();
    }

    public function test_the_editor_content_can_be_sent_to_oneself(): void
    {
        Mail::fake();
        $admin = $this->userWithRole('admin', ['name' => 'Carla']);

        $this->actingAs($admin)
            ->post(route('admin.mail.templates.test', 'auth.login_code'), $this->payload())
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        Mail::assertSent(RenderedMailable::class, fn (RenderedMailable $mail) => $mail->hasTo($admin->email)
            && str_contains($mail->mail->html, 'Hola Carla')
            && str_contains($mail->mail->html, '483920'));
        $this->assertSame(0, MailTemplate::query()->count());
    }

    public function test_the_simulated_provider_drafts_without_calling_ai(): void
    {
        $this->simulatedModel();

        $this->actingAs($this->userWithRole('admin'))
            ->postJson(route('admin.mail.templates.generate', 'automation.welcome'), [
                'description' => 'Bienvenida a los nuevos usuarios con los primeros pasos.',
                'format' => 'html',
                'locale' => 'es',
            ])
            ->assertOk()
            ->assertJsonPath('simulated', true)
            ->assertJsonPath('format', 'html')
            ->assertJsonPath('unknown', [])
            ->assertJson(fn ($json) => $json->where('body', fn (string $body) => str_contains($body, '{{ recipient_name }}') && ! str_contains($body, '<script'))->etc());

        $this->assertSame(0, MailTemplate::query()->count());
        $this->assertTrue(AuditEvent::query()->where('action', 'mail.template_generated')->exists());
    }

    public function test_a_real_model_drafts_through_the_gateway(): void
    {
        $this->aiModel();
        MailTemplateWriter::fake([[
            'subject' => 'Bienvenida a {{ app_name }}',
            'body' => "# Hola {{ recipient_name }}\n\n[[Entrar|{{ login_url }}]]",
        ]]);

        $this->actingAs($this->userWithRole('admin'))
            ->postJson(route('admin.mail.templates.generate', 'automation.welcome'), [
                'description' => 'Bienvenida corta con un botón para entrar.',
                'format' => 'markdown',
                'locale' => 'es',
            ])
            ->assertOk()
            ->assertJsonPath('simulated', false)
            ->assertJsonPath('subject', 'Bienvenida a {{ app_name }}');

        // The call is recorded in the ledger...
        $this->assertSame(1, AiRequest::query()->count());
    }

    public function test_drafting_needs_an_enabled_text_model(): void
    {
        $this->actingAs($this->userWithRole('admin'))
            ->postJson(route('admin.mail.templates.generate', 'automation.welcome'), [
                'description' => 'Bienvenida corta con un botón para entrar.',
                'format' => 'markdown',
                'locale' => 'es',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['description']);
    }
}
