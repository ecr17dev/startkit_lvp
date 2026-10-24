<?php

namespace Starter\Ai\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Starter\Ai\Models\Budget;
use Starter\Ai\Models\BudgetSpend;

class BudgetThresholdReached extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly Budget $budget,
        public readonly BudgetSpend $spend,
        public readonly int $threshold,
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
        return (new MailMessage)
            ->subject(__('AI budget ":name" reached :percent%', ['name' => $this->budget->name, 'percent' => $this->threshold]))
            ->line(__('The AI budget ":name" reached :percent% of its limit for the period :period.', [
                'name' => $this->budget->name,
                'percent' => $this->threshold,
                'period' => $this->spend->period_key,
            ]))
            ->line(__('Spent: :spent USD of :limit USD.', [
                'spent' => number_format((float) $this->spend->spent, 4),
                'limit' => number_format((float) $this->budget->limit_amount, 2),
            ]))
            ->line($this->budget->hard_limit
                ? __('New calls are blocked when the limit is reached.')
                : __('This budget only alerts: calls are not blocked.'))
            ->action(__('Open the AI usage dashboard'), url('/admin/ai/usage'));
    }
}
