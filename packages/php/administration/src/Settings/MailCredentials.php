<?php

namespace Starter\Administration\Settings;

use Illuminate\Support\Env;

/**
 * Credentials of the mail providers configurable from the panel.
 *
 * Each credential is stored encrypted in the settings, as an `env:NAME`
 * reference or left empty to use the key of the .env file (read through the
 * configuration, so it also works with a cached configuration).
 */
final class MailCredentials
{
    /**
     * Mailers selectable in the panel. "env" keeps the MAIL_* variables.
     */
    public const MAILERS = ['env', 'resend', 'smtp', 'postmark', 'mailgun', 'log'];

    /**
     * Setting that holds the credential of each mailer and the configuration
     * key used when it is empty.
     *
     * @var array<string, array{setting: string, fallback: string|null, variable: string|null}>
     */
    public const SECRETS = [
        'resend' => ['setting' => 'resend_key', 'fallback' => 'services.resend.key', 'variable' => 'RESEND_API_KEY'],
        'postmark' => ['setting' => 'postmark_token', 'fallback' => 'services.postmark.key', 'variable' => 'POSTMARK_API_KEY'],
        'mailgun' => ['setting' => 'mailgun_secret', 'fallback' => 'services.mailgun.secret', 'variable' => 'MAILGUN_SECRET'],
        'smtp' => ['setting' => 'password', 'fallback' => null, 'variable' => null],
    ];

    /**
     * Mailgun API host of each region.
     */
    public const MAILGUN_ENDPOINTS = ['us' => 'api.mailgun.net', 'eu' => 'api.eu.mailgun.net'];

    /**
     * Only mail keys may be referenced, never the application secrets
     * (APP_KEY, DB_*...).
     */
    public static function isAllowedReference(string $value): bool
    {
        return (bool) preg_match('/^env:(RESEND_API_KEY|POSTMARK_API_KEY|MAILGUN_SECRET|MAIL_PASSWORD|STARTER_MAIL_SECRET_[A-Z0-9_]{1,80})$/', $value);
    }

    /**
     * The credential a mailer will use, or null when none is available.
     *
     * @param  array<string, mixed>  $mail  The "mail" settings group.
     */
    public static function resolve(array $mail, string $mailer): ?string
    {
        $secret = self::SECRETS[$mailer] ?? null;

        if ($secret === null) {
            return null;
        }

        $value = $mail[$secret['setting']] ?? null;

        if (is_string($value) && $value !== '') {
            if (! str_starts_with($value, 'env:')) {
                return $value;
            }

            $resolved = self::isAllowedReference($value) ? Env::get(substr($value, 4)) : null;

            return is_string($resolved) && $resolved !== '' ? $resolved : null;
        }

        $fallback = $secret['fallback'] !== null ? config($secret['fallback']) : null;

        return is_string($fallback) && $fallback !== '' ? $fallback : null;
    }

    /**
     * The Laravel mailer configuration (for Mail::build) of the given mail
     * settings, so a configuration can be tried before it is saved.
     *
     * @param  array<string, mixed>  $mail
     * @return array<string, mixed>
     */
    public static function mailerConfig(array $mail): array
    {
        return match ($mail['mailer'] ?? 'env') {
            'smtp' => [
                'transport' => 'smtp',
                'host' => $mail['host'] ?? null,
                'port' => (int) ($mail['port'] ?? 587),
                'username' => $mail['username'] ?? null,
                'password' => self::resolve($mail, 'smtp'),
                'scheme' => ($mail['scheme'] ?? null) ?: null,
                'timeout' => 15,
            ],
            'resend' => ['transport' => 'resend', 'key' => self::resolve($mail, 'resend')],
            'postmark' => ['transport' => 'postmark', 'token' => self::resolve($mail, 'postmark')],
            'mailgun' => [
                'transport' => 'mailgun',
                'secret' => self::resolve($mail, 'mailgun'),
                'domain' => ($mail['mailgun_domain'] ?? null) ?: config('services.mailgun.domain'),
                'endpoint' => self::MAILGUN_ENDPOINTS[$mail['mailgun_region'] ?? 'us'] ?? self::MAILGUN_ENDPOINTS['us'],
                'scheme' => 'https',
            ],
            'log' => ['transport' => 'log'],
            default => (array) config('mail.mailers.'.config('starter.env_mailer', config('mail.default'))),
        };
    }

    /**
     * Where the credential of a mailer comes from, safe to display: never the
     * value, at most its last four characters.
     *
     * @param  array<string, mixed>  $mail
     * @return array{source: 'stored'|'reference'|'environment'|'missing', hint: string|null}
     */
    public static function describe(array $mail, string $mailer): array
    {
        $secret = self::SECRETS[$mailer];
        $value = $mail[$secret['setting']] ?? null;

        if (is_string($value) && $value !== '') {
            return str_starts_with($value, 'env:')
                ? ['source' => 'reference', 'hint' => $value]
                : ['source' => 'stored', 'hint' => mb_strlen($value) >= 12 ? '••••'.mb_substr($value, -4) : '••••'];
        }

        if (self::resolve($mail, $mailer) !== null) {
            return ['source' => 'environment', 'hint' => $secret['variable']];
        }

        return ['source' => 'missing', 'hint' => $secret['variable']];
    }
}
