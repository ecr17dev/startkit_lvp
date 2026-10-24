<?php

namespace App\Mail;

use Starter\Administration\Mail\MailTemplateDefinition;
use Starter\Administration\Mail\MailTemplates;

/**
 * Templates of the application: the test email and the defaults of the
 * mail automations (welcome, inactivity, announcement, system event).
 */
final class AppMailTemplates
{
    public static function register(MailTemplates $templates): void
    {
        $regards = fn () => __('Regards').",<br>\n{{ app_name }}";

        $templates->define(new MailTemplateDefinition(
            key: 'mail.test',
            category: 'system',
            name: 'Test email',
            description: 'Sent from the mail settings to verify the server.',
            variables: ['mailer' => ['description' => 'Mail provider used', 'sample' => 'resend']],
            default: fn () => [
                'subject' => __('Test email from :app', ['app' => '{{ app_name }}']),
                'body' => implode("\n\n", [
                    '# '.__('It works!'),
                    __('This is a test email sent from :app to verify the mail server configuration.', ['app' => '{{ app_name }}']),
                    __('Mailer').': **{{ mailer }}**',
                    $regards(),
                ]),
            ],
        ));

        $templates->define(new MailTemplateDefinition(
            key: 'automation.welcome',
            category: 'automation',
            name: 'Welcome email',
            description: 'Default of the welcome automation, after an account is created.',
            variables: [],
            default: fn () => [
                'subject' => __('Welcome to :app', ['app' => '{{ app_name }}']),
                'body' => implode("\n\n", [
                    '# '.__('Hello :name!', ['name' => '{{ recipient_name }}']),
                    __('Your account on :app is ready. Log in with your email: we send you a one-time code, no password needed.', ['app' => '{{ app_name }}']),
                    '[['.__('Log in').'|{{ login_url }}]]',
                    $regards(),
                ]),
            ],
        ));

        $templates->define(new MailTemplateDefinition(
            key: 'automation.inactivity',
            category: 'automation',
            name: 'We miss you',
            description: 'Default of the inactivity automation.',
            variables: ['days_inactive' => ['description' => 'Days since the last login', 'sample' => '30']],
            default: fn () => [
                'subject' => __('We miss you at :app', ['app' => '{{ app_name }}']),
                'body' => implode("\n\n", [
                    '# '.__('Hello :name!', ['name' => '{{ recipient_name }}']),
                    __('It has been :days days since your last visit. Your account is still waiting for you.', ['days' => '{{ days_inactive }}']),
                    '[['.__('Log in').'|{{ login_url }}]]',
                    $regards(),
                ]),
            ],
        ));

        $templates->define(new MailTemplateDefinition(
            key: 'automation.announcement',
            category: 'automation',
            name: 'Announcement',
            description: 'Default of the scheduled sends to a role.',
            variables: ['role' => ['description' => 'Role of the recipients', 'sample' => 'editor']],
            default: fn () => [
                'subject' => __('News from :app', ['app' => '{{ app_name }}']),
                'body' => implode("\n\n", [
                    '# '.__('Hello :name!', ['name' => '{{ recipient_name }}']),
                    __('Write your announcement here.'),
                    '[['.__('Open :app', ['app' => '{{ app_name }}']).'|{{ app_url }}]]',
                    $regards(),
                ]),
            ],
        ));

        $templates->define(new MailTemplateDefinition(
            key: 'automation.event',
            category: 'automation',
            name: 'System event',
            description: 'Default of the automations triggered by a system event.',
            variables: [
                'event_name' => ['description' => 'Event', 'sample' => 'Account deactivated'],
                'event_details' => ['description' => 'Event details', 'sample' => 'carla.soto@example.com'],
                'actor_name' => ['description' => 'Who did it', 'sample' => 'Owner'],
            ],
            default: fn () => [
                'subject' => __(':event on :app', ['event' => '{{ event_name }}', 'app' => '{{ app_name }}']),
                'body' => implode("\n\n", [
                    '# '.__('Hello :name!', ['name' => '{{ recipient_name }}']),
                    __('Event: :event', ['event' => '**{{ event_name }}**']),
                    __('Details: :details', ['details' => '{{ event_details }}']),
                    __('By: :actor', ['actor' => '{{ actor_name }}']),
                    $regards(),
                ]),
            ],
        ));
    }
}
