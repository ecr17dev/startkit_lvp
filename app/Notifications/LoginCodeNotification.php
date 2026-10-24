<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\HtmlString;

class LoginCodeNotification extends Notification
{
    public function __construct(
        public string $code,
        public int $minutes,
    ) {}

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $code = e($this->code);

        return (new MailMessage)
            ->subject(__('Your login code for :app', ['app' => config('app.name')]))
            ->greeting(__('Hello!'))
            ->line(__('Use the following code to log in to :app:', ['app' => config('app.name')]))
            ->line(new HtmlString(
                '<p style="font-size:32px;font-weight:700;letter-spacing:10px;text-align:center;margin:24px 0;">'.$code.'</p>'
            ))
            ->line(__('The code expires in :minutes minutes.', ['minutes' => $this->minutes]))
            ->line(__('If you did not request this code, you can safely ignore this email.'));
    }
}
