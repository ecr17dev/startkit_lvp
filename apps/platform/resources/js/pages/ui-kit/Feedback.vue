<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { FolderOpen, Plus, SearchX } from '@lucide/vue';
import { ref } from 'vue';
import EmptyState from '@/components/kit/EmptyState.vue';
import StatusAlert from '@/components/kit/StatusAlert.vue';
import Example from '@/components/ui-kit/Example.vue';
import { Button } from '@starter/ui/components/button';
import { Progress } from '@starter/ui/components/progress';
import { Skeleton } from '@starter/ui/components/skeleton';
import { Spinner } from '@starter/ui/components/spinner';
import { t } from '@/lib/i18n';
import { notify } from '@/lib/notify';
import { index, show } from '@/routes/ui-kit';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'UI kit', href: index() },
            { title: 'Alerts and toasts', href: show('feedback') },
        ],
    },
});

const progress = ref(64);

const wait = (ms: number, fail = false) =>
    new Promise<void>((resolve, reject) =>
        setTimeout(() => (fail ? reject(new Error('demo')) : resolve()), ms),
    );

function promiseToast() {
    notify.promise(wait(1500), {
        loading: t('Saving…'),
        success: t('Saved.'),
        error: t('Could not save.'),
    });
}

function undoToast() {
    notify.message(t('Project archived.'), {
        action: {
            label: t('Undo'),
            onClick: () => notify.success(t('Restored.')),
        },
    });
}

const alertCode = `
<StatusAlert tone="success" :title="t('Changes saved')">{{ t('...') }}</StatusAlert>
<StatusAlert tone="warning" :title="t('Budget almost spent')" dismissible>...</StatusAlert>
<StatusAlert tone="error" :title="t('Connection failed')">...</StatusAlert>
`;

const toastCode = `
import { notify } from '@/lib/notify';

notify.success(t('Saved.'));
notify.error(t('Could not save.'), { description: t('Try again in a minute.') });
notify.message(t('Project archived.'), { action: { label: t('Undo'), onClick: restore } });
notify.promise(save(), { loading: t('Saving…'), success: t('Saved.'), error: t('Could not save.') });

// From Laravel, after a redirect (same look):
Inertia::flash('toast', ['type' => 'success', 'message' => __('Project created.')]);
`;

const emptyCode = `
<EmptyState :icon="FolderOpen" :title="t('No projects yet')"
    :description="t('Projects group the work of a team. Create the first one.')">
    <Button><Plus class="size-4" /> {{ t('New project') }}</Button>
</EmptyState>
`;
</script>

<template>
    <Head :title="t('Alerts and toasts')" />

    <Example
        :title="t('Alerts')"
        :description="
            t(
                'Messages that stay on the page. The icon and the title tell the status; the color only supports them.',
            )
        "
        :code="alertCode"
    >
        <div class="grid gap-3">
            <StatusAlert tone="info" :title="t('New version available')">
                {{ t('Reload the page to use the latest version.') }}
            </StatusAlert>
            <StatusAlert tone="success" :title="t('Changes saved')">
                {{ t('The settings apply to new conversations.') }}
            </StatusAlert>
            <StatusAlert
                tone="warning"
                :title="t('Budget almost spent')"
                dismissible
            >
                {{ t('You used 92 % of this month budget.') }}
            </StatusAlert>
            <StatusAlert tone="error" :title="t('Connection failed')">
                {{ t('The provider did not answer. Check the credentials.') }}
            </StatusAlert>
        </div>
    </Example>

    <Example
        :title="t('Toasts')"
        :description="
            t(
                'Short confirmations that disappear alone. After a redirect, the server sends them with Inertia::flash.',
            )
        "
        :code="toastCode"
    >
        <div class="flex flex-wrap gap-2">
            <Button variant="outline" @click="notify.success(t('Saved.'))">
                {{ t('Success') }}
            </Button>
            <Button
                variant="outline"
                @click="
                    notify.error(t('Could not save.'), {
                        description: t('Try again in a minute.'),
                    })
                "
            >
                {{ t('Error') }}
            </Button>
            <Button
                variant="outline"
                @click="notify.warning(t('The session expires in 5 minutes.'))"
            >
                {{ t('Warning') }}
            </Button>
            <Button
                variant="outline"
                @click="notify.info(t('There are 3 new comments.'))"
            >
                {{ t('Information') }}
            </Button>
            <Button variant="outline" @click="undoToast">
                {{ t('With action') }}
            </Button>
            <Button variant="outline" @click="promiseToast">
                {{ t('Promise') }}
            </Button>
        </div>
    </Example>

    <Example
        :title="t('Empty states')"
        :description="
            t(
                'Say what is missing and offer the next step. Without results, suggest changing the filters.',
            )
        "
        :code="emptyCode"
    >
        <div class="grid gap-4 lg:grid-cols-2">
            <EmptyState
                :icon="FolderOpen"
                :title="t('No projects yet')"
                :description="
                    t(
                        'Projects group the work of a team. Create the first one.',
                    )
                "
            >
                <Button><Plus class="size-4" /> {{ t('New project') }}</Button>
            </EmptyState>
            <EmptyState
                :icon="SearchX"
                :title="t('No results')"
                :description="t('Try other words or clear the filters.')"
            >
                <Button variant="outline">{{ t('Clear filters') }}</Button>
            </EmptyState>
        </div>
    </Example>

    <Example
        :title="t('Loading and progress')"
        :description="
            t(
                'Skeletons keep the layout while data arrives; spinners for short waits; progress when the amount is known.',
            )
        "
    >
        <div class="grid gap-6 lg:grid-cols-2">
            <div class="flex items-center gap-4">
                <Skeleton class="size-12 rounded-full" />
                <div class="grid flex-1 gap-2">
                    <Skeleton class="h-4 w-3/4" />
                    <Skeleton class="h-4 w-1/2" />
                </div>
            </div>
            <div class="grid gap-3">
                <div class="flex items-center gap-2 text-sm">
                    <Spinner /> {{ t('Loading…') }}
                </div>
                <div class="grid gap-1.5">
                    <div class="flex justify-between text-sm">
                        <span>{{ t('Indexing documents') }}</span>
                        <span class="tabular-nums">{{ progress }} %</span>
                    </div>
                    <Progress :model-value="progress" />
                </div>
                <div class="flex gap-2">
                    <Button
                        size="sm"
                        variant="outline"
                        @click="progress = Math.max(0, progress - 10)"
                        >−10</Button
                    >
                    <Button
                        size="sm"
                        variant="outline"
                        @click="progress = Math.min(100, progress + 10)"
                        >+10</Button
                    >
                </div>
            </div>
        </div>
    </Example>
</template>
