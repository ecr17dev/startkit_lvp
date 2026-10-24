<?php

namespace Starter\Identity\Notifications;

use Illuminate\Notifications\AnonymousNotifiable;
use Starter\Administration\Mail\MailTemplateDefinition;
use Starter\Administration\Mail\MailTemplates;
use Starter\Identity\Otp\OtpPurpose;

/**
 * Default templates of the access emails (codes, invitation and password
 * reset), editable in Admin → Mail → Templates.
 */
final class IdentityMailTemplates
{
    public static function key(OtpPurpose $purpose): string
    {
        return match ($purpose) {
            OtpPurpose::Login => 'auth.login_code',
            OtpPurpose::Register => 'auth.register_code',
            OtpPurpose::Invitation => 'auth.invitation_code',
            OtpPurpose::Confirm => 'auth.confirm_code',
        };
    }

    public static function register(MailTemplates $templates): void
    {
        $code = [
            'code' => ['description' => 'One-time code', 'sample' => '483920'],
            'expires_minutes' => ['description' => 'Minutes until it expires', 'sample' => '5'],
        ];

        $codes = [
            OtpPurpose::Login->value => ['Login code', 'Sent when someone asks to log in.', 'Your login code for :app', 'Use this code to log in to :app:'],
            OtpPurpose::Register->value => ['Email confirmation', 'Sent to confirm the email of a new account.', 'Confirm your email for :app', 'Use this code to confirm your email and create your account:'],
            OtpPurpose::Invitation->value => ['Invitation code', 'Sent to confirm the email when accepting an invitation.', 'Accept your invitation to :app', 'Use this code to confirm your email and accept the invitation:'],
            OtpPurpose::Confirm->value => ['Action confirmation', 'Sent to confirm a sensitive action.', 'Confirm it is you on :app', 'Use this code to confirm a sensitive action:'],
        ];

        foreach ($codes as $purpose => [$name, $description, $subject, $intro]) {
            $templates->define(new MailTemplateDefinition(
                key: self::key(OtpPurpose::from($purpose)),
                category: 'access',
                name: $name,
                description: $description,
                variables: $code,
                default: fn () => [
                    'subject' => __($subject, ['app' => '{{ app_name }}']),
                    'body' => self::lines([
                        '# '.__('Hello!'),
                        __($intro, ['app' => '{{ app_name }}']),
                        '# {{ code }}',
                        __('The code expires in :minutes minutes and can only be used once.', ['minutes' => '{{ expires_minutes }}']),
                        __('If you did not request this code, you can ignore this email. Never share it with anyone.'),
                        __('Regards').",<br>\n{{ app_name }}",
                    ]),
                ],
            ));
        }

        $templates->define(new MailTemplateDefinition(
            key: 'auth.invitation',
            category: 'access',
            name: 'Invitation',
            description: 'Sent when an administrator invites someone.',
            variables: [
                'inviter_name' => ['description' => 'Who invited', 'sample' => 'Carla Soto'],
                'accept_url' => ['description' => 'Link to accept', 'sample' => url('/invitations/sample')],
                'expires_on' => ['description' => 'Expiration date', 'sample' => now()->addDays(7)->isoFormat('LL')],
            ],
            default: fn () => [
                'subject' => __('You have been invited to :app', ['app' => '{{ app_name }}']),
                'body' => self::lines([
                    '# '.__('Hello!'),
                    __(':name invited you to join :app.', ['name' => '{{ inviter_name }}', 'app' => '{{ app_name }}']),
                    '[['.__('Accept invitation').'|{{ accept_url }}]]',
                    __('The invitation expires on :date.', ['date' => '{{ expires_on }}']),
                    __('If you were not expecting this invitation, you can ignore this email.'),
                    __('Regards').",<br>\n{{ app_name }}",
                ]),
            ],
        ));

        $templates->define(new MailTemplateDefinition(
            key: 'auth.password_reset',
            category: 'access',
            name: 'Password reset',
            description: 'Sent when someone asks to reset the password (only with password login).',
            variables: [
                'reset_url' => ['description' => 'Link to choose a new password', 'sample' => url('/reset-password/sample')],
                'expires_minutes' => ['description' => 'Minutes until it expires', 'sample' => '60'],
            ],
            default: fn () => [
                'subject' => __('Reset Password Notification'),
                'body' => self::lines([
                    '# '.__('Hello!'),
                    __('You are receiving this email because we received a password reset request for your account.'),
                    '[['.__('Reset Password').'|{{ reset_url }}]]',
                    __('This password reset link will expire in :count minutes.', ['count' => '{{ expires_minutes }}']),
                    __('If you did not request a password reset, no further action is required.'),
                    __('Regards').",<br>\n{{ app_name }}",
                ]),
            ],
        ));
    }

    /**
     * Name and email of a notifiable (a user or an on-demand address).
     *
     * @return array{recipient_name: string, recipient_email: string}
     */
    public static function recipient(object $notifiable): array
    {
        if ($notifiable instanceof AnonymousNotifiable) {
            return ['recipient_name' => '', 'recipient_email' => (string) ($notifiable->routes['mail'] ?? '')];
        }

        return [
            'recipient_name' => (string) ($notifiable->name ?? ''),
            'recipient_email' => (string) ($notifiable->email ?? ''),
        ];
    }

    /**
     * @param  list<string>  $lines
     */
    public static function lines(array $lines): string
    {
        return implode("\n\n", $lines);
    }
}
