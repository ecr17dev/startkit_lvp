<script setup lang="ts">
import { Laptop, Smartphone } from '@lucide/vue';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import { Badge } from '@starter/ui/components/badge';
import { Button } from '@starter/ui/components/button';
import { formatDate, t } from '@/lib/i18n';
import type { BrowserSession } from '@/types';

/**
 * Browser sessions of a user with a revoke action per session.
 */
defineProps<{
    sessions: BrowserSession[];
    canRevoke?: boolean;
}>();

const emit = defineEmits<{ revoke: [session: BrowserSession] }>();

const isMobile = (session: BrowserSession) =>
    ['iOS', 'Android'].includes(session.platform);
</script>

<template>
    <ul class="divide-y rounded-lg border" data-test="session-list">
        <li
            v-for="session in sessions"
            :key="session.id"
            class="flex items-center gap-3 p-3"
        >
            <component
                :is="isMobile(session) ? Smartphone : Laptop"
                class="size-5 shrink-0 text-muted-foreground"
                aria-hidden="true"
            />
            <div class="min-w-0 flex-1">
                <div class="flex flex-wrap items-center gap-2 text-sm">
                    <span class="font-medium"
                        >{{ session.browser }} · {{ session.platform }}</span
                    >
                    <Badge v-if="session.current" variant="secondary">{{
                        t('This device')
                    }}</Badge>
                </div>
                <p class="text-xs text-muted-foreground">
                    {{ session.ip_address ?? t('Unknown IP') }} ·
                    {{
                        t('Last active :date', {
                            date: formatDate(session.last_active_at),
                        })
                    }}
                </p>
            </div>
            <ConfirmDialog
                v-if="canRevoke && !session.current"
                :title="t('Revoke session')"
                :description="
                    t('The device will be logged out on its next request.')
                "
                :confirm-text="t('Revoke')"
                @confirm="emit('revoke', session)"
            >
                <Button variant="ghost" size="sm">{{ t('Revoke') }}</Button>
            </ConfirmDialog>
        </li>
        <li
            v-if="!sessions.length"
            class="p-6 text-center text-sm text-muted-foreground"
        >
            {{ t('No active sessions.') }}
        </li>
    </ul>
</template>
