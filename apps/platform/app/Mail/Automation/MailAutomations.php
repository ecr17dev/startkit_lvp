<?php

namespace App\Mail\Automation;

use App\Jobs\DeliverMail;
use App\Models\MailAutomation;
use App\Models\MailDelivery;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Starter\Administration\Models\AuditEvent;

/**
 * Runs the mail automations: welcome after an account is created,
 * inactivity, scheduled sends to a role and system events (audit trail).
 * Every email is a MailDelivery with a delivery key, sent by the queue, so
 * an automation never mails the same person twice for the same occurrence.
 */
class MailAutomations
{
    /**
     * System events that can trigger an automation. "person" tells which user
     * the event is about (the audited subject or the actor), who can be the
     * recipient; null when it is about no one in particular.
     *
     * @var array<string, array{label: string, person: 'subject'|'actor'|null}>
     */
    public const EVENTS = [
        'users.created' => ['label' => 'User created', 'person' => 'subject'],
        'auth.registered' => ['label' => 'New registration', 'person' => 'subject'],
        'invitations.accepted' => ['label' => 'Invitation accepted', 'person' => 'actor'],
        'users.disabled' => ['label' => 'Account deactivated', 'person' => 'subject'],
        'users.enabled' => ['label' => 'Account reactivated', 'person' => 'subject'],
        'users.roles_changed' => ['label' => 'Role changed', 'person' => 'subject'],
        'ai.budget.threshold' => ['label' => 'AI budget threshold reached', 'person' => null],
    ];

    /**
     * Active automations by trigger, loaded once per request or job.
     *
     * @var array<string, list<MailAutomation>>
     */
    protected array $active = [];

    /**
     * @return list<MailAutomation>
     */
    protected function active(string $trigger): array
    {
        return $this->active[$trigger] ??= array_values(MailAutomation::query()->where('trigger', $trigger)->where('is_active', true)->get()->all());
    }

    public function flush(): void
    {
        $this->active = [];
    }

    public function userCreated(User $user): void
    {
        foreach ($this->active('user_created') as $automation) {
            $delay = max(0, (int) $automation->setting('delay_minutes', 0));

            $this->send($automation, $user, [], "a{$automation->id}:u{$user->id}", now()->addMinutes($delay));
        }
    }

    public function audited(AuditEvent $event): void
    {
        $key = $event->action === 'users.updated' && isset($event->properties['roles']) ? 'users.roles_changed' : $event->action;

        if (! isset(self::EVENTS[$key]) || $this->active('event') === []) {
            return;
        }

        $actor = $event->actor_id !== null ? User::query()->find($event->actor_id) : null;
        $person = match (self::EVENTS[$key]['person']) {
            'subject' => $event->subject_type === 'user' && $event->subject_id !== null ? User::query()->find($event->subject_id) : null,
            'actor' => $actor,
            default => null,
        };

        foreach ($this->active('event') as $automation) {
            if ($automation->setting('event') !== $key) {
                continue;
            }

            $recipients = $automation->setting('recipients') === 'person'
                ? array_filter([$person])
                : $this->usersOfRole($automation->setting('role'))->get()->all();

            foreach ($recipients as $recipient) {
                $this->send($automation, $recipient, [
                    'event_name' => __(self::EVENTS[$key]['label']),
                    'event_details' => $this->details($key, $event, $person),
                    'actor_name' => $actor->name ?? __('System'),
                ], "a{$automation->id}:e{$event->id}:u{$recipient->id}");
            }
        }
    }

    /**
     * Inactivity and scheduled sends (the scheduler runs it every five
     * minutes).
     *
     * @return array{inactivity: int, scheduled: int}
     */
    public function runDue(?CarbonInterface $now = null): array
    {
        $now = CarbonImmutable::instance($now ?? now());
        $counts = ['inactivity' => 0, 'scheduled' => 0];

        foreach ($this->active('inactivity') as $automation) {
            $days = max(1, (int) $automation->setting('days', 30));

            // Users not mailed by this automation since their last login...
            $this->usersOfRole($automation->setting('role'))
                ->whereRaw('coalesce(last_login_at, created_at) <= ?', [$now->subDays($days)])
                ->whereNotExists(fn ($query) => $query->selectRaw('1')
                    ->from('mail_deliveries')
                    ->where('mail_deliveries.automation_id', $automation->id)
                    ->whereColumn('mail_deliveries.user_id', 'users.id')
                    ->whereRaw('mail_deliveries.created_at >= coalesce(users.last_login_at, users.created_at)'))
                ->orderBy('id')
                ->limit(500)
                ->get()
                ->each(function (User $user) use ($automation, $now, &$counts) {
                    $since = CarbonImmutable::instance($user->last_login_at ?? $user->created_at);

                    if ($this->send($automation, $user, ['days_inactive' => (int) $since->diffInDays($now)], "a{$automation->id}:u{$user->id}:s{$since->timestamp}")) {
                        $counts['inactivity']++;
                    }
                });

            $automation->forceFill(['last_run_at' => $now])->saveQuietly();
        }

        foreach ($this->active('scheduled') as $automation) {
            $occurrence = $this->occurrence($automation, $now);

            if ($occurrence === null || ($automation->last_run_at !== null && $automation->last_run_at->greaterThanOrEqualTo($occurrence))) {
                continue;
            }

            $role = $automation->setting('role');

            $this->usersOfRole($role)->lazyById(500)->each(function (User $user) use ($automation, $occurrence, $role, &$counts) {
                if ($this->send($automation, $user, ['role' => (string) ($role ?? '')], "a{$automation->id}:u{$user->id}:o{$occurrence->timestamp}")) {
                    $counts['scheduled']++;
                }
            });

            $automation->forceFill(['last_run_at' => $occurrence])->saveQuietly();
        }

        return $counts;
    }

    /**
     * The latest occurrence of a scheduled automation that is due now, or
     * null. Occurrences before the automation existed, or more than a day
     * old, are not sent.
     */
    public function occurrence(MailAutomation $automation, CarbonInterface $now): ?CarbonImmutable
    {
        $timezone = (string) ($automation->setting('timezone') ?: settings('general.timezone') ?: config('app.timezone'));
        $local = CarbonImmutable::instance($now)->setTimezone($timezone);
        [$hour, $minute] = array_map('intval', explode(':', (string) $automation->setting('time', '09:00')) + [1 => 0]);

        $occurrence = match ($automation->setting('frequency')) {
            'once' => CarbonImmutable::parse((string) $automation->setting('date'), $timezone)->setTime($hour, $minute),
            'weekly' => $this->weekly($local, (int) $automation->setting('weekday', 1), $hour, $minute),
            'monthly' => $this->monthly($local, (int) $automation->setting('day', 1), $hour, $minute),
            default => null,
        };

        if ($occurrence === null || $occurrence->greaterThan($now) || $occurrence->lessThan($automation->created_at) || $occurrence->lessThan(CarbonImmutable::instance($now)->subDay())) {
            return null;
        }

        return $occurrence->utc();
    }

    /**
     * Queue one email. Returns null when the delivery key was already used.
     *
     * @param  array<string, scalar|null>  $variables
     */
    public function send(MailAutomation $automation, User|string $recipient, array $variables, ?string $deliveryKey, ?CarbonInterface $sendAt = null): ?MailDelivery
    {
        try {
            $delivery = MailDelivery::query()->create([
                'automation_id' => $automation->id,
                'template_key' => $automation->template_key,
                'user_id' => $recipient instanceof User ? $recipient->id : null,
                'email' => $recipient instanceof User ? $recipient->email : $recipient,
                'variables' => $variables,
                'status' => 'pending',
                'delivery_key' => $deliveryKey,
                'send_at' => $sendAt ?? now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            return null;
        }

        $job = DeliverMail::dispatch($delivery->id)->afterCommit();

        if ($sendAt !== null && $sendAt->isFuture()) {
            $job->delay($sendAt);
        }

        return $delivery;
    }

    /**
     * Active users of a role (all active users without a role).
     *
     * @return Builder<User>
     */
    protected function usersOfRole(mixed $role): Builder
    {
        return User::query()
            ->where('is_active', true)
            ->when(is_string($role) && $role !== '', fn (Builder $query) => $query->role((string) $role));
    }

    protected function details(string $key, AuditEvent $event, ?User $person): string
    {
        $properties = (array) $event->properties;

        return match ($key) {
            'users.roles_changed' => ($person->email ?? '').': '.implode(', ', (array) ($properties['roles']['from'] ?? [])).' → '.implode(', ', (array) ($properties['roles']['to'] ?? [])),
            'ai.budget.threshold' => ($properties['threshold'] ?? '').'% · '.($properties['spent'] ?? '').' USD · '.($properties['period'] ?? ''),
            default => (string) ($person->email ?? $properties['email'] ?? ''),
        };
    }

    protected function weekly(CarbonImmutable $local, int $weekday, int $hour, int $minute): CarbonImmutable
    {
        $occurrence = $local->startOfWeek()->addDays(max(1, min(7, $weekday)) - 1)->setTime($hour, $minute);

        return $occurrence->greaterThan($local) ? $occurrence->subWeek() : $occurrence;
    }

    protected function monthly(CarbonImmutable $local, int $day, int $hour, int $minute): CarbonImmutable
    {
        $day = max(1, min(28, $day));
        $occurrence = $local->startOfMonth()->addDays($day - 1)->setTime($hour, $minute);

        return $occurrence->greaterThan($local) ? $local->subMonthNoOverflow()->startOfMonth()->addDays($day - 1)->setTime($hour, $minute) : $occurrence;
    }
}
