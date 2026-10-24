<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ArrowRight, Check, ShieldAlert, X } from '@lucide/vue';
import type { ChatProposal } from '@starter/chat';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { Badge } from '@starter/ui/components/badge';
import { Button } from '@starter/ui/components/button';
import { t } from '@/lib/i18n';

const props = defineProps<{ proposal: ChatProposal; busy?: boolean }>();
const emit = defineEmits<{ decide: [decision: 'approve' | 'reject'] }>();

const fieldLabels: Record<string, string> = {
    name: 'Name',
    email: 'Email',
    locale: 'Language',
    roles: 'Roles',
    role: 'Role',
    status: 'Status',
    account: 'Account',
    permissions: 'Permissions',
    granted: 'Permissions granted',
    revoked: 'Permissions revoked',
};

const statusLabels: Record<string, string> = {
    pending: 'Waiting for your confirmation',
    approved: 'Confirmed, running…',
    executing: 'Running…',
    executed: 'Done',
    failed: 'Failed',
    rejected: 'Cancelled',
    expired: 'Expired',
    superseded: 'Not confirmed',
};

const now = ref(Date.now());
let timer: ReturnType<typeof setInterval> | undefined;

onMounted(() => (timer = setInterval(() => (now.value = Date.now()), 1000)));
onBeforeUnmount(() => clearInterval(timer));

const secondsLeft = computed(() =>
    Math.max(
        0,
        Math.round(
            (new Date(props.proposal.expires_at).getTime() - now.value) / 1000,
        ),
    ),
);

const status = computed(() =>
    props.proposal.status === 'pending' && secondsLeft.value === 0
        ? 'expired'
        : props.proposal.status,
);

const pending = computed(() => status.value === 'pending');

const format = (value: unknown): string => {
    if (value === null || value === undefined || value === '') {
        return '—';
    }

    if (Array.isArray(value)) {
        return value.length ? value.join(', ') : '—';
    }

    return String(value);
};

const countdown = computed(() => {
    const minutes = Math.floor(secondsLeft.value / 60);
    const seconds = String(secondsLeft.value % 60).padStart(2, '0');

    return `${minutes}:${seconds}`;
});

const resultMessage = computed(() => {
    const message = props.proposal.result?.message;

    return typeof message === 'string' ? message : null;
});
</script>

<template>
    <div
        class="grid gap-3 rounded-xl border bg-card p-4 text-sm shadow-xs"
        :class="pending ? 'border-amber-500/60' : ''"
        data-test="proposal-card"
    >
        <div class="flex items-start justify-between gap-3">
            <div class="flex items-start gap-2">
                <ShieldAlert
                    class="mt-0.5 size-4 shrink-0"
                    :class="
                        pending ? 'text-amber-600' : 'text-muted-foreground'
                    "
                />
                <div class="grid gap-0.5">
                    <p class="font-medium">{{ proposal.preview.title }}</p>
                    <p
                        v-if="proposal.preview.target.label"
                        class="text-xs text-muted-foreground"
                    >
                        {{ proposal.preview.target.label }}
                    </p>
                </div>
            </div>
            <Badge
                :variant="
                    status === 'executed'
                        ? 'default'
                        : status === 'failed'
                          ? 'destructive'
                          : pending
                            ? 'secondary'
                            : 'outline'
                "
                class="shrink-0"
                >{{ t(statusLabels[status] ?? status) }}</Badge
            >
        </div>

        <dl v-if="proposal.preview.changes.length" class="grid gap-1.5">
            <div
                v-for="change in proposal.preview.changes"
                :key="change.field"
                class="grid grid-cols-[minmax(6rem,auto)_1fr] items-baseline gap-2"
            >
                <dt class="text-xs text-muted-foreground">
                    {{ t(fieldLabels[change.field] ?? change.field) }}
                </dt>
                <dd class="flex flex-wrap items-center gap-1.5">
                    <span
                        v-if="change.before !== null"
                        class="text-muted-foreground line-through decoration-muted-foreground/50"
                        >{{ format(change.before) }}</span
                    >
                    <ArrowRight
                        v-if="change.before !== null && change.after !== null"
                        class="size-3 text-muted-foreground"
                    />
                    <span v-if="change.after !== null" class="font-medium">{{
                        format(change.after)
                    }}</span>
                </dd>
            </div>
        </dl>

        <ul
            v-if="proposal.preview.effects.length"
            class="list-disc pl-5 text-xs text-muted-foreground"
        >
            <li v-for="effect in proposal.preview.effects" :key="effect">
                {{ effect }}
            </li>
        </ul>

        <p v-if="status === 'executed' && resultMessage" class="text-xs">
            {{ resultMessage }}
            <Link
                v-if="proposal.preview.url"
                :href="proposal.preview.url"
                class="underline"
                >{{ t('View') }}</Link
            >
        </p>
        <p
            v-if="status === 'failed' && proposal.error"
            class="text-xs text-destructive"
        >
            {{ proposal.error }}
        </p>

        <div v-if="pending" class="flex flex-wrap items-center gap-2">
            <Button
                size="sm"
                :variant="
                    proposal.kind === 'sensitive' ? 'destructive' : 'default'
                "
                :disabled="busy"
                data-test="proposal-confirm"
                @click="emit('decide', 'approve')"
            >
                <Check class="size-4" />
                {{ t('Confirm') }}
            </Button>
            <Button
                size="sm"
                variant="outline"
                :disabled="busy"
                data-test="proposal-cancel"
                @click="emit('decide', 'reject')"
            >
                <X class="size-4" />
                {{ t('Cancel') }}
            </Button>
            <span class="text-xs text-muted-foreground">
                {{ t('Expires in') }} {{ countdown }}
            </span>
        </div>
    </div>
</template>
