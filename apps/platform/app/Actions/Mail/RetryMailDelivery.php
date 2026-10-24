<?php

namespace App\Actions\Mail;

use App\Jobs\DeliverMail;
use App\Models\MailDelivery;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Queue a failed or skipped send again.
 */
class RetryMailDelivery
{
    public function handle(User $actor, MailDelivery $delivery): void
    {
        Gate::forUser($actor)->authorize('mail.manage');

        if (! in_array($delivery->status, ['failed', 'skipped'], true)) {
            throw ValidationException::withMessages(['delivery' => __('Only failed or skipped sends can be retried.')]);
        }

        $delivery->update(['status' => 'pending', 'error' => null, 'send_at' => now()]);
        DeliverMail::dispatch($delivery->id);

        audit('mail.delivery_retried', null, ['delivery' => $delivery->id, 'email' => $delivery->email, 'template' => $delivery->template_key], $actor);
    }
}
