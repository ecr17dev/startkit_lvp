<?php

namespace App\Actions\Settings;

use App\Models\User;
use Closure;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Starter\Administration\Settings\MailCredentials;
use Starter\Administration\Settings\SettingsManager;

/**
 * Save the mail provider used for login codes, invitations and
 * notifications. Shared by the panel and `starter:config-apply`.
 *
 * Secrets are stored encrypted (or as an allowed `env:NAME` reference); a
 * blank secret keeps the stored one. An API provider cannot be selected
 * without a usable key, so a typo never locks everybody out of the login.
 */
class UpdateMailSettings
{
    /**
     * Setting that holds each removable secret.
     */
    public const SECRET_FIELDS = ['password', 'resend_key', 'postmark_token', 'mailgun_secret'];

    public function __construct(protected SettingsManager $settings) {}

    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        $secret = ['nullable', 'string', 'max:500', function (string $attribute, mixed $value, Closure $fail) {
            if (is_string($value) && str_starts_with($value, 'env:') && ! MailCredentials::isAllowedReference($value)) {
                $fail(__('Only RESEND_API_KEY, POSTMARK_API_KEY, MAILGUN_SECRET, MAIL_PASSWORD or STARTER_MAIL_SECRET_* can be referenced.'));
            }
        }];

        return [
            'mailer' => ['required', Rule::in(MailCredentials::MAILERS)],
            'host' => ['nullable', 'required_if:mailer,smtp', 'string', 'max:255'],
            'port' => ['nullable', 'required_if:mailer,smtp', 'integer', 'between:1,65535'],
            'username' => ['nullable', 'string', 'max:255'],
            'password' => $secret,
            'scheme' => ['nullable', Rule::in(['smtp', 'smtps'])],
            'resend_key' => [...$secret, function (string $attribute, mixed $value, Closure $fail) {
                if (is_string($value) && $value !== '' && ! str_starts_with($value, 'env:') && ! str_starts_with($value, 're_')) {
                    $fail(__('Resend API keys start with re_.'));
                }
            }],
            'postmark_token' => $secret,
            'mailgun_secret' => $secret,
            'mailgun_domain' => ['nullable', 'string', 'max:255', 'regex:/^(?!-)[a-z0-9-]+(\.[a-z0-9-]+)+$/i'],
            'mailgun_region' => ['nullable', Rule::in(array_keys(MailCredentials::MAILGUN_ENDPOINTS))],
            'remove' => ['array'],
            'remove.*' => [Rule::in(self::SECRET_FIELDS)],
            'from_address' => ['required', 'email', 'max:255'],
            'from_name' => ['required', 'string', 'max:255'],
        ];
    }

    /**
     * @param  User|null  $actor  null when run from the console.
     * @param  array<string, mixed>  $input
     * @return array<string, mixed> The saved settings, without secrets.
     */
    public function handle(?User $actor, array $input): array
    {
        if ($actor !== null) {
            Gate::forUser($actor)->authorize('settings.update');
        }

        [$values, $mail] = $this->prepare($input);

        $this->settings->setGroup('mail', $values);

        audit('settings.updated', null, [
            'group' => 'mail',
            'mailer' => $values['mailer'],
            'keys' => array_keys($values),
        ], $actor);

        // Long running queue workers must reload the configuration (the
        // signal of queue:restart, without nesting an Artisan call)...
        Cache::forever('illuminate:queue:restart', now()->getTimestamp());

        return Arr::except($mail, self::SECRET_FIELDS);
    }

    /**
     * Validate the input and merge it with the stored settings without
     * saving anything (also used to try a configuration before saving it).
     *
     * @param  array<string, mixed>  $input
     * @return array{0: array<string, mixed>, 1: array<string, mixed>} [values to store, resulting settings]
     */
    public function prepare(array $input): array
    {
        $data = Validator::make($input, self::rules())->validate();
        $current = $this->settings->group('mail');

        $values = Arr::except($data, [...self::SECRET_FIELDS, 'remove']);

        if (isset($values['mailgun_domain'])) {
            $values['mailgun_domain'] = mb_strtolower($values['mailgun_domain']);
        }

        // A blank secret keeps the stored one; a reference to the standard
        // variable (env:RESEND_API_KEY...) is the same as no stored key...
        $standard = collect(MailCredentials::SECRETS)->mapWithKeys(fn (array $secret) => [$secret['setting'] => 'env:'.$secret['variable']]);

        foreach (self::SECRET_FIELDS as $field) {
            if (filled($data[$field] ?? null)) {
                $values[$field] = $data[$field] === $standard[$field] ? null : $data[$field];
            } elseif (in_array($field, $data['remove'] ?? [], true)) {
                $values[$field] = null;
            }
        }

        $mail = [...$current, ...$values];

        $this->ensureUsable($mail);

        return [$values, $mail];
    }

    /**
     * Reject a provider that cannot send with the resulting settings.
     *
     * @param  array<string, mixed>  $mail
     */
    protected function ensureUsable(array $mail): void
    {
        $mailer = $mail['mailer'];

        if (in_array($mailer, ['resend', 'postmark', 'mailgun'], true) && MailCredentials::resolve($mail, $mailer) === null) {
            $secret = MailCredentials::SECRETS[$mailer];

            throw ValidationException::withMessages([
                $secret['setting'] => __('Paste the API key or set :variable in the .env file.', ['variable' => $secret['variable']]),
            ]);
        }

        if ($mailer === 'mailgun' && blank($mail['mailgun_domain'] ?? null) && blank(config('services.mailgun.domain'))) {
            throw ValidationException::withMessages([
                'mailgun_domain' => __('The sending domain verified in Mailgun is required.'),
            ]);
        }
    }
}
