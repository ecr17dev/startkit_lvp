<?php

namespace Starter\Identity\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Starter\Administration\Mail\MailTemplates;
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
        return app(MailTemplates::class)->render(IdentityMailTemplates::key($this->purpose), [
            'code' => $this->code,
            'expires_minutes' => $this->ttl,
            ...IdentityMailTemplates::recipient($notifiable),
        ])->toMailMessage();
    }
}
