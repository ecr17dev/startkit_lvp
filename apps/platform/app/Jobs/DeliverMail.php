<?php

namespace App\Jobs;

use App\Models\MailDelivery;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Mail;
use Starter\Administration\Mail\TemplateMail;
use Throwable;

/**
 * Sends one pending MailDelivery with its template. Retries twice; after the
 * last attempt the delivery is marked as failed with the provider error.
 */
class DeliverMail implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /**
     * @var list<int>
     */
    public array $backoff = [60, 300];

    public function __construct(public int $deliveryId)
    {
        $this->onQueue('mail');
    }

    public function handle(): void
    {
        $delivery = MailDelivery::query()->with(['user', 'automation'])->find($this->deliveryId);

        if ($delivery === null || $delivery->status !== 'pending') {
            return;
        }

        $user = $delivery->user;

        if ($delivery->automation !== null && ! $delivery->automation->is_active) {
            $delivery->update(['status' => 'skipped', 'error' => __('The automation is paused.')]);

            return;
        }

        if ($user instanceof User && ! $user->is_active && $delivery->automation?->trigger !== 'event') {
            $delivery->update(['status' => 'skipped', 'error' => __('The account is deactivated.')]);

            return;
        }

        $mailable = new TemplateMail($delivery->template_key, [
            ...(array) $delivery->variables,
            'recipient_name' => $user->name ?? '',
            'recipient_email' => $delivery->email,
        ]);
        $mailable->locale($user->locale ?? app()->getLocale());

        try {
            Mail::to($delivery->email)->send($mailable);
        } catch (Throwable $exception) {
            $delivery->update(['error' => mb_substr($exception->getMessage(), 0, 1000)]);

            throw $exception;
        }

        $delivery->update([
            'status' => 'sent',
            'subject' => mb_substr($mailable->rendered()->subject, 0, 255),
            'error' => null,
            'sent_at' => now(),
        ]);
    }

    public function failed(?Throwable $exception): void
    {
        MailDelivery::query()
            ->whereKey($this->deliveryId)
            ->where('status', 'pending')
            ->update(['status' => 'failed', 'error' => mb_substr((string) $exception?->getMessage(), 0, 1000)]);
    }
}
