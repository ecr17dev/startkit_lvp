<?php

namespace Starter\Identity\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Starter\Administration\Mail\MailTemplates;
use Starter\Identity\Models\Invitation;

class InvitationNotification extends Notification
{
    public function __construct(
        public readonly Invitation $invitation,
        public readonly string $url,
        public readonly ?string $inviterName = null,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $appName = (string) (settings('branding.app_name') ?: config('app.name'));

        return app(MailTemplates::class)->render('auth.invitation', [
            'inviter_name' => $this->inviterName ?: __('The :app team', ['app' => $appName]),
            'accept_url' => $this->url,
            'expires_on' => $this->invitation->expires_at->isoFormat('LL'),
            ...IdentityMailTemplates::recipient($notifiable),
        ])->toMailMessage();
    }
}
