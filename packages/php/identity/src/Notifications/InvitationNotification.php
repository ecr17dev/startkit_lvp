<?php

namespace Starter\Identity\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
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
        $appName = (string) settings('branding.app_name', config('app.name'));

        return (new MailMessage)
            ->subject(__('You have been invited to :app', ['app' => $appName]))
            ->line($this->inviterName
                ? __(':name invited you to join :app.', ['name' => $this->inviterName, 'app' => $appName])
                : __('You have been invited to join :app.', ['app' => $appName]))
            ->action(__('Accept invitation'), $this->url)
            ->line(__('The invitation expires on :date.', ['date' => $this->invitation->expires_at->toFormattedDayDateString()]))
            ->line(__('If you were not expecting this invitation, you can ignore this email.'));
    }
}
