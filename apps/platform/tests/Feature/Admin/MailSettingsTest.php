<?php

namespace Tests\Feature\Admin;

use App\Actions\Settings\UpdateMailSettings;
use App\Mail\TestMail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;
use Starter\Administration\Models\AuditEvent;
use Starter\Administration\Settings\RuntimeConfiguration;
use Tests\TestCase;

class MailSettingsTest extends TestCase
{
    use RefreshDatabase;

    private const RESEND_KEY = 're_test1234_abcdefghijklmnopqrstuv';

    protected function setUp(): void
    {
        parent::setUp();

        // No key in the environment unless a test sets one...
        config(['services.resend.key' => null, 'services.postmark.key' => null, 'services.mailgun.secret' => null, 'services.mailgun.domain' => null]);
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function payload(array $values = []): array
    {
        return [
            'mailer' => 'resend',
            'from_address' => 'hola@acme.test',
            'from_name' => 'Acme',
            ...$values,
        ];
    }

    private function applyRuntime(): void
    {
        app(RuntimeConfiguration::class)->apply();
    }

    public function test_the_page_describes_credentials_without_exposing_them(): void
    {
        $admin = $this->userWithRole('admin');
        settings()->set(['mail.mailer' => 'resend', 'mail.resend_key' => self::RESEND_KEY, 'mail.password' => 'smtp-secret']);
        config(['services.postmark.key' => 'postmark-from-env']);

        $this->actingAs($admin)
            ->get(route('admin.settings.mail.edit'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/settings/Mail')
                ->where('settings.mailer', 'resend')
                ->missing('settings.resend_key')
                ->missing('settings.password')
                ->where('credentials.resend.source', 'stored')
                ->where('credentials.resend.hint', '••••stuv')
                ->where('credentials.smtp.hint', '••••')
                ->where('credentials.postmark.source', 'environment')
                ->where('credentials.postmark.hint', 'POSTMARK_API_KEY')
                ->where('credentials.mailgun.source', 'missing'))
            ->assertDontSee(self::RESEND_KEY)
            ->assertDontSee('postmark-from-env')
            ->assertDontSee('smtp-secret');
    }

    public function test_resend_is_saved_encrypted_and_applied(): void
    {
        $admin = $this->userWithRole('admin');

        $this->actingAs($admin)
            ->put(route('admin.settings.mail.update'), $this->payload(['resend_key' => self::RESEND_KEY]))
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $stored = DB::table('settings')->where('key', 'mail.resend_key')->value('value');
        $this->assertIsString($stored);
        $this->assertStringNotContainsString(self::RESEND_KEY, $stored);

        $this->applyRuntime();

        $this->assertSame('resend', config('mail.default'));
        $this->assertSame(self::RESEND_KEY, config('services.resend.key'));
        $this->assertSame('hola@acme.test', config('mail.from.address'));

        $event = AuditEvent::query()->where('action', 'settings.updated')->latest('id')->firstOrFail();
        $this->assertSame('resend', $event->properties['mailer']);
        $this->assertStringNotContainsString(self::RESEND_KEY, (string) json_encode($event->properties));
    }

    public function test_an_api_provider_without_a_key_is_rejected(): void
    {
        $admin = $this->userWithRole('admin');

        $this->actingAs($admin)
            ->put(route('admin.settings.mail.update'), $this->payload())
            ->assertSessionHasErrors('resend_key');

        $this->assertSame('env', settings('mail.mailer'));
    }

    public function test_a_blank_key_uses_the_one_of_the_env_file(): void
    {
        $admin = $this->userWithRole('admin');
        config(['services.resend.key' => self::RESEND_KEY]);

        $this->actingAs($admin)
            ->put(route('admin.settings.mail.update'), $this->payload(['resend_key' => '']))
            ->assertSessionHasNoErrors();

        $this->assertNull(settings('mail.resend_key'));

        $this->applyRuntime();

        $this->assertSame('resend', config('mail.default'));
        $this->assertSame(self::RESEND_KEY, config('services.resend.key'));
    }

    public function test_keys_are_checked_and_only_mail_variables_can_be_referenced(): void
    {
        $admin = $this->userWithRole('admin');

        $this->actingAs($admin)
            ->put(route('admin.settings.mail.update'), $this->payload(['resend_key' => 'sk-not-a-resend-key']))
            ->assertSessionHasErrors('resend_key');

        $this->actingAs($admin)
            ->put(route('admin.settings.mail.update'), $this->payload(['resend_key' => 'env:APP_KEY']))
            ->assertSessionHasErrors('resend_key');

        putenv('STARTER_MAIL_SECRET_RESEND='.self::RESEND_KEY);
        $_ENV['STARTER_MAIL_SECRET_RESEND'] = self::RESEND_KEY;

        try {
            $this->actingAs($admin)
                ->put(route('admin.settings.mail.update'), $this->payload(['resend_key' => 'env:STARTER_MAIL_SECRET_RESEND']))
                ->assertSessionHasNoErrors();

            $this->assertSame('env:STARTER_MAIL_SECRET_RESEND', settings('mail.resend_key'));

            $this->applyRuntime();

            $this->assertSame(self::RESEND_KEY, config('services.resend.key'));
        } finally {
            putenv('STARTER_MAIL_SECRET_RESEND');
            unset($_ENV['STARTER_MAIL_SECRET_RESEND']);
        }
    }

    public function test_mailgun_needs_its_domain_and_uses_the_region_endpoint(): void
    {
        $admin = $this->userWithRole('admin');

        $this->actingAs($admin)
            ->put(route('admin.settings.mail.update'), $this->payload(['mailer' => 'mailgun', 'mailgun_secret' => 'mg-secret-value']))
            ->assertSessionHasErrors('mailgun_domain');

        $this->actingAs($admin)
            ->put(route('admin.settings.mail.update'), $this->payload([
                'mailer' => 'mailgun',
                'mailgun_secret' => 'mg-secret-value',
                'mailgun_domain' => 'MG.Acme.test',
                'mailgun_region' => 'eu',
            ]))
            ->assertSessionHasNoErrors();

        $this->applyRuntime();

        $this->assertSame('mailgun', config('mail.default'));
        $this->assertSame('mg.acme.test', config('services.mailgun.domain'));
        $this->assertSame('api.eu.mailgun.net', config('services.mailgun.endpoint'));
        $this->assertSame('mg-secret-value', config('services.mailgun.secret'));
    }

    public function test_postmark_is_applied(): void
    {
        $admin = $this->userWithRole('admin');

        $this->actingAs($admin)
            ->put(route('admin.settings.mail.update'), $this->payload(['mailer' => 'postmark', 'postmark_token' => 'pm-token-value']))
            ->assertSessionHasNoErrors();

        $this->applyRuntime();

        $this->assertSame('postmark', config('mail.default'));
        $this->assertSame('pm-token-value', config('services.postmark.key'));
    }

    public function test_a_blank_smtp_password_keeps_the_stored_one_until_it_is_removed(): void
    {
        $admin = $this->userWithRole('admin');
        $smtp = $this->payload(['mailer' => 'smtp', 'host' => 'smtp.acme.test', 'port' => 465, 'scheme' => 'smtps', 'username' => 'mailer']);

        $this->actingAs($admin)->put(route('admin.settings.mail.update'), [...$smtp, 'password' => 'first-password'])->assertSessionHasNoErrors();
        $this->actingAs($admin)->put(route('admin.settings.mail.update'), [...$smtp, 'password' => ''])->assertSessionHasNoErrors();

        $this->assertSame('first-password', settings('mail.password'));

        $this->applyRuntime();

        $this->assertSame('smtp', config('mail.default'));
        $this->assertSame('smtp.acme.test', config('mail.mailers.smtp.host'));
        $this->assertSame('first-password', config('mail.mailers.smtp.password'));

        $this->actingAs($admin)->put(route('admin.settings.mail.update'), [...$smtp, 'remove' => ['password']])->assertSessionHasNoErrors();

        $this->assertNull(settings('mail.password'));
    }

    public function test_mail_settings_require_permission(): void
    {
        $editor = $this->userWithRole('editor');

        $this->actingAs($editor)->get(route('admin.settings.mail.edit'))->assertForbidden();
        $this->actingAs($editor)
            ->put(route('admin.settings.mail.update'), $this->payload(['resend_key' => self::RESEND_KEY]))
            ->assertForbidden();

        $this->assertNull(settings('mail.resend_key'));
    }

    public function test_a_test_email_is_sent_with_the_saved_configuration(): void
    {
        Mail::fake();
        $admin = $this->userWithRole('admin');

        $this->actingAs($admin)
            ->post(route('admin.settings.mail.test'), ['email' => 'qa@acme.test'])
            ->assertSessionHasNoErrors();

        Mail::assertSent(TestMail::class, fn (TestMail $mail) => $mail->hasTo('qa@acme.test'));
    }

    public function test_the_action_is_usable_from_the_console(): void
    {
        config(['services.resend.key' => self::RESEND_KEY]);

        $saved = app(UpdateMailSettings::class)->handle(null, $this->payload(['resend_key' => 'env:RESEND_API_KEY']));

        $this->assertSame('resend', $saved['mailer']);
        $this->assertArrayNotHasKey('resend_key', $saved);
        // The standard variable is the same as no stored key...
        $this->assertNull(settings('mail.resend_key'));
    }
}
