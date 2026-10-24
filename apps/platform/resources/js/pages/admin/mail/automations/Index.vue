<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Pencil, Plus, Trash2, Workflow } from '@lucide/vue';
import { ActionsMenu, EmptyState } from '@/components/kit';
import type { MenuAction } from '@/components/kit';
import PageHeader from '@/components/PageHeader.vue';
import { Badge } from '@starter/ui/components/badge';
import { Button } from '@starter/ui/components/button';
import { Switch } from '@starter/ui/components/switch';
import { usePermissions } from '@/composables/usePermissions';
import { formatDate, t } from '@/lib/i18n';
import type { MailAutomation, MailEvent } from '@/lib/mailAutomations';
import { describeAutomation, triggers } from '@/lib/mailAutomations';
import {
    create,
    destroy,
    edit,
    index,
    toggle,
} from '@/routes/admin/mail/automations';
import { index as deliveries } from '@/routes/admin/mail/deliveries';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Mail', href: index() }],
    },
});

type Row = MailAutomation & {
    template_name: string;
    last_run_at: string | null;
    counts: { sent: number; pending: number; failed: number };
};

defineProps<{
    automations: Row[];
    events: MailEvent[];
}>();

const { can } = usePermissions();

const trigger = (row: Row) =>
    triggers.find((item) => item.value === row.trigger) ?? triggers[0];

const setActive = (row: Row) =>
    router.patch(toggle(row.id).url, {}, { preserveScroll: true });

const actions = (row: Row): MenuAction[] => [
    { label: t('Edit'), icon: Pencil, href: edit(row.id) },
    {
        label: t('Delete'),
        icon: Trash2,
        destructive: true,
        separated: true,
        confirm: {
            title: t('Delete automation'),
            description: t(
                'It stops sending. Its sends stay in the log; the pending ones are skipped.',
            ),
            action: t('Delete'),
        },
        onSelect: () =>
            router.delete(destroy(row.id).url, { preserveScroll: true }),
    },
];
</script>

<template>
    <Head :title="t('Mail automations')" />

    <PageHeader
        :title="t('Mail automations')"
        :description="
            t(
                'Emails that go out on their own: welcome, inactivity, scheduled sends and system events. Each person gets each occurrence only once.',
            )
        "
    >
        <Button v-if="can('mail.manage')" as-child data-test="new-automation">
            <Link :href="create()">
                <Plus />
                {{ t('New automation') }}
            </Link>
        </Button>
    </PageHeader>

    <EmptyState
        v-if="automations.length === 0"
        :icon="Workflow"
        :title="t('No automations yet')"
        :description="
            t(
                'Start with a welcome email for new accounts or a reminder for users who stopped coming.',
            )
        "
    >
        <Button v-if="can('mail.manage')" as-child>
            <Link :href="create()">{{ t('Create the first one') }}</Link>
        </Button>
    </EmptyState>

    <ul v-else class="grid gap-3" data-test="automations">
        <li
            v-for="automation in automations"
            :key="automation.id"
            class="flex flex-col gap-4 rounded-lg border p-4 sm:flex-row sm:items-center"
        >
            <div
                class="flex size-10 shrink-0 items-center justify-center rounded-md bg-muted"
            >
                <component
                    :is="trigger(automation).icon"
                    class="size-5 text-muted-foreground"
                />
            </div>

            <div class="min-w-0 flex-1 space-y-1">
                <div class="flex flex-wrap items-center gap-2">
                    <Link
                        v-if="can('mail.manage')"
                        :href="edit(automation.id)"
                        class="font-medium hover:underline"
                        >{{ automation.name }}</Link
                    >
                    <span v-else class="font-medium">{{
                        automation.name
                    }}</span>
                    <Badge variant="secondary">{{
                        t(trigger(automation).title)
                    }}</Badge>
                    <Badge v-if="!automation.is_active" variant="outline">{{
                        t('Paused')
                    }}</Badge>
                </div>
                <p class="text-sm text-muted-foreground">
                    {{ describeAutomation(automation, events) }}
                </p>
                <p class="text-xs text-muted-foreground">
                    {{ t('Template') }}: {{ automation.template_name }}
                    <template v-if="automation.last_run_at">
                        · {{ t('Last run') }}
                        {{ formatDate(automation.last_run_at) }}
                    </template>
                </p>
            </div>

            <div class="flex items-center gap-4">
                <Link
                    :href="deliveries({ query: { automation: automation.id } })"
                    class="flex gap-3 text-sm hover:underline"
                    :aria-label="t('Sends of :name', { name: automation.name })"
                >
                    <span
                        ><strong>{{ automation.counts.sent }}</strong>
                        {{ t('sent') }}</span
                    >
                    <span v-if="automation.counts.pending"
                        ><strong>{{ automation.counts.pending }}</strong>
                        {{ t('pending') }}</span
                    >
                    <span
                        v-if="automation.counts.failed"
                        class="text-destructive"
                        ><strong>{{ automation.counts.failed }}</strong>
                        {{ t('failed') }}</span
                    >
                </Link>
                <Switch
                    v-if="can('mail.manage')"
                    :model-value="automation.is_active"
                    :aria-label="
                        automation.is_active ? t('Pause') : t('Activate')
                    "
                    :data-test="`toggle-${automation.id}`"
                    @update:model-value="setActive(automation)"
                />
                <ActionsMenu
                    v-if="can('mail.manage')"
                    :items="actions(automation)"
                />
            </div>
        </li>
    </ul>
</template>
