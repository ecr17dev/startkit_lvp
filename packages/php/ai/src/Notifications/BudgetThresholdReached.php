<?php

namespace Starter\Ai\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Starter\Administration\Mail\MailTemplates;
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
        return app(MailTemplates::class)->render('ai.budget_threshold', [
            'budget_name' => $this->budget->name,
            'percent' => $this->threshold,
            'period' => $this->spend->period_key,
            'spent' => number_format((float) $this->spend->spent, 4),
            'limit' => number_format((float) $this->budget->limit_amount, 2),
            'limit_behavior' => $this->budget->hard_limit
                ? __('New calls are blocked when the limit is reached.')
                : __('This budget only alerts: calls are not blocked.'),
            'dashboard_url' => url('/admin/ai/usage'),
            'recipient_name' => (string) ($notifiable->name ?? ''),
            'recipient_email' => (string) ($notifiable->email ?? ''),
        ])->toMailMessage();
    }
}
