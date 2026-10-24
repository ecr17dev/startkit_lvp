<?php

namespace Starter\Identity\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Starter\Identity\Otp\OtpPurpose;

class OneTimeCodeNotification extends Notification
{
    public function __construct(
        public readonly string $code,
        public readonly OtpPurpose $purpose,
        public readonly int $ttl,
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

        [$subject, $intro] = match ($this->purpose) {
            OtpPurpose::Login => [__('Your login code for :app', ['app' => $appName]), __('Use this code to log in to :app:', ['app' => $appName])],
            OtpPurpose::Register => [__('Confirm your email for :app', ['app' => $appName]), __('Use this code to confirm your email and create your account:')],
            OtpPurpose::Invitation => [__('Accept your invitation to :app', ['app' => $appName]), __('Use this code to confirm your email and accept the invitation:')],
            OtpPurpose::Confirm => [__('Confirm it is you on :app', ['app' => $appName]), __('Use this code to confirm a sensitive action:')],
        };

        return (new MailMessage)
            ->subject($subject)
            ->line($intro)
            ->line('# '.$this->code)
            ->line(__('The code expires in :minutes minutes and can only be used once.', ['minutes' => $this->ttl]))
            ->line(__('If you did not request this code, you can ignore this email. Never share it with anyone.'));
    }
}
