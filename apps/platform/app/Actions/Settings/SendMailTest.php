<?php

namespace App\Actions\Settings;

use App\Mail\TestMail;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Starter\Administration\Settings\MailCredentials;
use Starter\Administration\Settings\SettingsManager;
use Throwable;

/**
 * Send a test email with the saved mail settings or, when given, with the
 * values of the form before saving them (blank secrets use the stored
 * ones). The outcome is kept as mail.last_test for the panel.
 */
class SendMailTest
{
    public function __construct(
        protected SettingsManager $settings,
        protected UpdateMailSettings $update,
    ) {}

    /**
     * @param  array<string, mixed>|null  $values  unsaved form values
     * @return array{at: string, ok: bool, mailer: string, to: string, error: string|null}
     */
    public function handle(User $actor, string $email, ?array $values = null): array
    {
        Gate::forUser($actor)->authorize('settings.update');

        Validator::make(['email' => $email], ['email' => ['required', 'email', 'max:255']])->validate();

        $mail = $values === null ? $this->settings->group('mail') : $this->update->prepare($values)[1];
        $mailable = new TestMail;

        if (($mail['mailer'] ?? 'env') !== 'env') {
            $mailable->from((string) $mail['from_address'], (string) $mail['from_name']);
        }

        try {
            Mail::build(MailCredentials::mailerConfig($mail))->to($email)->send($mailable);
            $error = null;
        } catch (Throwable $exception) {
            $error = mb_substr($exception->getMessage(), 0, 500);
        }

        $result = [
            'at' => now()->toIso8601String(),
            'ok' => $error === null,
            'mailer' => (string) ($mail['mailer'] ?? 'env'),
            'to' => $email,
            'error' => $error,
        ];

        $this->settings->set(['mail.last_test' => $result]);

        audit('settings.mail_tested', null, ['mailer' => $result['mailer'], 'ok' => $result['ok'], 'saved' => $values === null], $actor);

        return $result;
    }
}
