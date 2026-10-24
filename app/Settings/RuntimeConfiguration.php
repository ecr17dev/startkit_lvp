<?php

namespace App\Settings;

use App\Ai\ProviderRegistry;

/**
 * Applies the settings stored in the database on top of the file based
 * configuration. Runs once per request / job after the app is booted.
 */
class RuntimeConfiguration
{
    public function __construct(protected SettingsManager $settings) {}

    public function apply(): void
    {
        $this->applyApplication();
        $this->applyMail();
        $this->applyAi();
    }

    protected function applyApplication(): void
    {
        if (filled($name = $this->settings->get('branding.app_name'))) {
            config(['app.name' => $name]);
        }

        $locale = $this->settings->get('general.locale');

        if (is_string($locale) && array_key_exists($locale, (array) config('settings.locales'))) {
            config(['app.locale' => $locale]);
            app()->setLocale($locale);
        }
    }

    protected function applyMail(): void
    {
        $mail = $this->settings->group('mail');

        // Remember the mailer configured in the .env file before overriding it...
        config(['settings.env_mailer' => config('settings.env_mailer', config('mail.default'))]);

        match ($mail['mailer'] ?? 'env') {
            'smtp' => config([
                'mail.default' => 'smtp',
                'mail.mailers.smtp.url' => null,
                'mail.mailers.smtp.host' => $mail['host'] ?? null,
                'mail.mailers.smtp.port' => (int) ($mail['port'] ?? 587),
                'mail.mailers.smtp.username' => $mail['username'] ?? null,
                'mail.mailers.smtp.password' => $mail['password'] ?? null,
                'mail.mailers.smtp.scheme' => ($mail['scheme'] ?? null) ?: null,
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

    protected function applyAi(): void
    {
        config(['ai.conversations.generate_title' => (bool) $this->settings->get('ai.generate_titles', true)]);

        ProviderRegistry::apply();
    }
}
