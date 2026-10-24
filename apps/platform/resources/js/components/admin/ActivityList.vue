<script setup lang="ts">
import { Badge } from '@starter/ui/components/badge';
import { auditActionLabel } from '@/lib/audit';
import { formatDate, t } from '@/lib/i18n';
import type { AuditEvent } from '@/types';

defineProps<{ events: AuditEvent[] }>();
</script>

<template>
    <ol class="relative space-y-4 border-l pl-4" data-test="activity-list">
        <li v-for="event in events" :key="event.id" class="space-y-1">
            <span
                class="absolute -left-1.5 mt-1.5 size-3 rounded-full border bg-background"
                aria-hidden="true"
            />
            <div class="flex flex-wrap items-center gap-2 text-sm">
                <span class="font-medium">{{
                    auditActionLabel(event.action)
                }}</span>
                <Badge variant="outline" class="uppercase">{{
                    event.channel
                }}</Badge>
            </div>
            <p class="text-xs text-muted-foreground">
                {{ formatDate(event.created_at) }}
                <template v-if="event.actor">
                    ·
                    {{
                        t('by :name', {
                            name: event.actor.name ?? '#' + event.actor.id,
                        })
                    }}
                </template>
                <template v-if="event.ip_address">
                    · {{ event.ip_address }}</template
                >
            </p>
        </li>
        <li v-if="!events.length" class="text-sm text-muted-foreground">
            {{ t('No activity recorded yet.') }}
        </li>
    </ol>
</template>
