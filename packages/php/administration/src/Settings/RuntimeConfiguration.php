<?php

namespace Starter\Administration\Settings;

use Closure;

/**
 * Applies the settings stored in the database on top of the file based
 * configuration. Runs once per request / job after the app is booted.
 *
 * Only application wide values (name, default locale, mail transport) are
 * applied here. Values that depend on the current user or agent (AI
 * connections, models...) are resolved per execution by their modules.
 */
class RuntimeConfiguration
{
    /**
     * Extra appliers registered by other modules.
     *
     * @var list<Closure(SettingsManager): void>
     */
    protected array $appliers = [];

    public function __construct(protected SettingsManager $settings) {}

    /**
     * Register an extra applier.
     *
     * @param  Closure(SettingsManager): void  $applier
     */
    public function extend(Closure $applier): void
    {
        $this->appliers[] = $applier;
    }

    public function apply(): void
    {
        $this->applyApplication();
        $this->applyMail();

        foreach ($this->appliers as $applier) {
            $applier($this->settings);
        }
    }

    protected function applyApplication(): void
    {
        if (filled($name = $this->settings->get('branding.app_name'))) {
            config(['app.name' => $name]);
        }

        $locale = $this->settings->get('general.locale');

        if (is_string($locale) && array_key_exists($locale, (array) config('starter.locales'))) {
            config(['app.locale' => $locale]);
            app()->setLocale($locale);
        }
    }

    protected function applyMail(): void
    {
        $mail = $this->settings->group('mail');

        // Remember the mailer configured in the .env file before overriding it...
        config(['starter.env_mailer' => config('starter.env_mailer', config('mail.default'))]);

        // API keys go to the services configuration read by the transports;
        // an empty key keeps the one of the .env file...
        match ($mail['mailer'] ?? 'env') {
            'smtp' => config([
                'mail.default' => 'smtp',
                'mail.mailers.smtp.url' => null,
                'mail.mailers.smtp.host' => $mail['host'] ?? null,
                'mail.mailers.smtp.port' => (int) ($mail['port'] ?? 587),
                'mail.mailers.smtp.username' => $mail['username'] ?? null,
                'mail.mailers.smtp.password' => MailCredentials::resolve($mail, 'smtp'),
                'mail.mailers.smtp.scheme' => ($mail['scheme'] ?? null) ?: null,
            ]),
            'resend' => config([
                'mail.default' => 'resend',
                'services.resend.key' => MailCredentials::resolve($mail, 'resend'),
            ]),
            'postmark' => config([
                'mail.default' => 'postmark',
                'services.postmark.key' => MailCredentials::resolve($mail, 'postmark'),
            ]),
            'mailgun' => config([
                'mail.default' => 'mailgun',
                'services.mailgun.secret' => MailCredentials::resolve($mail, 'mailgun'),
                'services.mailgun.domain' => ($mail['mailgun_domain'] ?? null) ?: config('services.mailgun.domain'),
                'services.mailgun.endpoint' => MailCredentials::MAILGUN_ENDPOINTS[$mail['mailgun_region'] ?? 'us'] ?? MailCredentials::MAILGUN_ENDPOINTS['us'],
            ]),
            'log' => config(['mail.default' => 'log']),
            default => null,
        };

        if (($mail['mailer'] ?? 'env') !== 'env') {
            config([
                'mail.from.address' => $mail['from_address'] ?? config('mail.from.address'),
                'mail.from.name' => $mail['from_name'] ?? config('app.name'),
            ]);
        }
    }
}
