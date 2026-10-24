<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Model;
use Starter\Administration\Models\AuditEvent;

/**
 * Presents audit events in the panel.
 */
class AuditLog
{
    /**
     * @return list<array<string, mixed>>
     */
    public static function forSubject(Model $subject, int $limit = 20): array
    {
        return array_values(AuditEvent::query()
            ->with('actor')
            ->where('subject_type', $subject->getMorphClass())
            ->where('subject_id', (string) $subject->getKey())
            ->latest('id')
            ->limit($limit)
            ->get()
            ->map(fn (AuditEvent $event) => self::present($event))
            ->all());
    }

    /**
     * @return array<string, mixed>
     */
    public static function present(AuditEvent $event): array
    {
        $actor = $event->actor;

        return [
            'id' => $event->id,
            'action' => $event->action,
            'channel' => $event->channel,
            'actor' => $event->actor_id !== null ? [
                'id' => $event->actor_id,
                'name' => $actor?->getAttribute('name') ?: $event->actor_label,
            ] : null,
            'subject_type' => $event->subject_type,
            'subject_id' => $event->subject_id,
            'properties' => $event->properties ?? (object) [],
            'ip_address' => $event->ip_address,
            'request_id' => $event->request_id,
            'created_at' => $event->created_at->toIso8601String(),
        ];
    }
}
