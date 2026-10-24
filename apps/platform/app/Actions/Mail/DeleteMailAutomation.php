<?php

namespace App\Actions\Mail;

use App\Models\MailAutomation;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * Delete an automation. Its sends stay in the log; the pending ones are
 * skipped.
 */
class DeleteMailAutomation
{
    public function handle(User $actor, MailAutomation $automation): void
    {
        Gate::forUser($actor)->authorize('mail.manage');

        $automation->deliveries()->where('status', 'pending')->update(['status' => 'skipped', 'error' => __('The automation was deleted.')]);
        $automation->delete();

        audit('mail.automation_deleted', null, ['automation' => $automation->id, 'name' => $automation->name], $actor);
    }
}
