<?php

namespace Starter\Ai\Notifications;

use Starter\Administration\Mail\MailTemplateDefinition;
use Starter\Administration\Mail\MailTemplates;

/**
 * Default template of the AI budget alert, editable in Admin → Mail.
 */
final class AiMailTemplates
{
    public static function register(MailTemplates $templates): void
    {
        $templates->define(new MailTemplateDefinition(
            key: 'ai.budget_threshold',
            category: 'ai',
            name: 'AI budget alert',
            description: 'Sent to the budget recipients when spending crosses a threshold.',
            variables: [
                'budget_name' => ['description' => 'Budget name', 'sample' => 'Chat'],
                'percent' => ['description' => 'Threshold reached (%)', 'sample' => '80'],
                'period' => ['description' => 'Budget period', 'sample' => now()->format('Y-m')],
                'spent' => ['description' => 'Amount spent (USD)', 'sample' => '40.0000'],
                'limit' => ['description' => 'Budget limit (USD)', 'sample' => '50.00'],
                'limit_behavior' => ['description' => 'What happens at the limit', 'sample' => 'This budget only alerts: calls are not blocked.'],
                'dashboard_url' => ['description' => 'AI usage dashboard', 'sample' => url('/admin/ai/usage')],
            ],
            default: fn () => [
                'subject' => __('AI budget ":name" reached :percent%', ['name' => '{{ budget_name }}', 'percent' => '{{ percent }}']),
                'body' => implode("\n\n", [
                    '# '.__('Hello!'),
                    __('The AI budget ":name" reached :percent% of its limit for the period :period.', ['name' => '{{ budget_name }}', 'percent' => '{{ percent }}', 'period' => '{{ period }}']),
                    __('Spent: :spent USD of :limit USD.', ['spent' => '{{ spent }}', 'limit' => '{{ limit }}']),
                    '{{ limit_behavior }}',
                    '[['.__('Open the AI usage dashboard').'|{{ dashboard_url }}]]',
                    __('Regards').",<br>\n{{ app_name }}",
                ]),
            ],
        ));
    }
}
