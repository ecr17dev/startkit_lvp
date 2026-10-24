<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { Bot, Rocket, Save, Settings2, Trash2, Undo2 } from '@lucide/vue';
import { ref, watch } from 'vue';
import AgentSettingsForm from '@/components/admin/agent/AgentSettingsForm.vue';
import type { AgentForm } from '@/components/admin/agent/AgentSettingsForm.vue';
import AgentTestPanel from '@/components/admin/agent/AgentTestPanel.vue';
import AgentVersions from '@/components/admin/agent/AgentVersions.vue';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import InputError from '@/components/InputError.vue';
import PageHeader from '@/components/PageHeader.vue';
import {
    Alert,
    AlertDescription,
    AlertTitle,
} from '@starter/ui/components/alert';
import { Badge } from '@starter/ui/components/badge';
import { Button } from '@starter/ui/components/button';
import {
    Card,
    CardContent,
    CardFooter,
    CardHeader,
    CardTitle,
} from '@starter/ui/components/card';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@starter/ui/components/dialog';
import { Input } from '@starter/ui/components/input';
import { Label } from '@starter/ui/components/label';
import { Switch } from '@starter/ui/components/switch';
import { Textarea } from '@starter/ui/components/textarea';
import { t } from '@/lib/i18n';
import agentRoutes from '@/routes/admin/ai/agents';
import type {
    AgentCheck,
    AgentConfig,
    AgentOptions,
    AgentSummary,
    AgentVersionRow,
} from '@/types';

const props = defineProps<{
    agent: AgentSummary;
    config: AgentConfig;
    notes: string | null;
    check: AgentCheck;
    versions: AgentVersionRow[];
    options: AgentOptions;
}>();

defineOptions({
    layout: (props: { agent: AgentSummary }) => ({
        breadcrumbs: [
            { title: 'Agents', href: agentRoutes.index() },
            { title: props.agent.name, href: agentRoutes.edit(props.agent.id) },
        ],
    }),
});

const clone = <T>(value: T): T => JSON.parse(JSON.stringify(value)) as T;

const form = useForm<AgentForm>({
    config: clone(props.config),
    notes: props.notes ?? '',
});

// Reload the form after saving, publishing or restoring...
watch(
    () => [props.config, props.notes] as const,
    ([config, notes]) => {
        form.defaults({ config: clone(config), notes: notes ?? '' });
        form.reset();
    },
);

const blankToNull = (value: unknown) =>
    value === '' || value === undefined || value === null
        ? null
        : Number(value);

const saveDraft = () =>
    form
        .transform((data) => ({
            ...data,
            config: {
                ...data.config,
                responses: {
                    ...data.config.responses,
                    max_tokens: blankToNull(data.config.responses.max_tokens),
                    temperature: blankToNull(data.config.responses.temperature),
                },
                context: {
                    ...data.config.context,
                    retention_days: blankToNull(
                        data.config.context.retention_days,
                    ),
                },
                limits: {
                    requests_per_minute: blankToNull(
                        data.config.limits.requests_per_minute,
                    ),
                    concurrent_per_user: blankToNull(
                        data.config.limits.concurrent_per_user,
                    ),
                },
                identity: {
                    ...data.config.identity,
                    avatar: data.config.identity.avatar || null,
                    greeting: data.config.identity.greeting || null,
                },
                microphone: {
                    ...data.config.microphone,
                    language: data.config.microphone.language || null,
                },
            },
        }))
        .put(agentRoutes.draft.update(props.agent.id).url, {
            preserveScroll: true,
        });

const publishOpen = ref(false);
const publishForm = useForm({ notes: '' });
const publish = () =>
    publishForm.post(agentRoutes.publish(props.agent.id).url, {
        preserveScroll: true,
        onSuccess: () => {
            publishOpen.value = false;
            publishForm.reset();
        },
    });

const detailsOpen = ref(false);
const details = useForm({
    name: props.agent.name,
    description: props.agent.description ?? '',
    is_active: props.agent.is_active,
    is_default: props.agent.is_default,
});
const saveDetails = () =>
    details.put(agentRoutes.update(props.agent.id).url, {
        preserveScroll: true,
        onSuccess: () => (detailsOpen.value = false),
    });

const discardDraft = () =>
    router.delete(agentRoutes.draft.destroy(props.agent.id).url, {
        preserveScroll: true,
    });
const destroy = () => router.delete(agentRoutes.destroy(props.agent.id).url);
</script>

<template>
    <Head :title="agent.name" />

    <div class="flex flex-col gap-6 p-4 md:p-6">
        <PageHeader :title="agent.name" :description="agent.description ?? ''">
            <template #icon>
                <span
                    class="flex size-10 items-center justify-center rounded-full bg-muted text-xl"
                >
                    <template v-if="config.identity.avatar">{{
                        config.identity.avatar
                    }}</template>
                    <Bot v-else class="size-5" />
                </span>
            </template>
            <div class="flex flex-wrap items-center gap-2">
                <Badge v-if="agent.published_version"
                    >{{ t('Published') }} v{{
                        agent.published_version.number
                    }}</Badge
                >
                <Badge v-else variant="outline">{{ t('Not published') }}</Badge>
                <Badge v-if="agent.draft" variant="secondary"
                    >{{ t('Draft') }} v{{ agent.draft.number }}</Badge
                >
                <Button variant="outline" size="sm" @click="detailsOpen = true">
                    <Settings2 class="size-4" />
                    {{ t('Details') }}
                </Button>
                <ConfirmDialog
                    v-if="agent.draft && agent.published_version"
                    :title="t('Discard the draft?')"
                    :description="
                        t(
                            'The unpublished changes are lost. The published version keeps serving conversations.',
                        )
                    "
                    :confirm-text="t('Discard')"
                    @confirm="discardDraft"
                >
                    <Button variant="outline" size="sm">
                        <Undo2 class="size-4" />
                        {{ t('Discard draft') }}
                    </Button>
                </ConfirmDialog>
                <Button
                    size="sm"
                    :disabled="
                        !agent.draft || check.errors.length > 0 || form.isDirty
                    "
                    @click="publishOpen = true"
                >
                    <Rocket class="size-4" />
                    {{ t('Publish draft') }}
                </Button>
            </div>
        </PageHeader>

        <Alert v-if="check.errors.length" variant="destructive">
            <AlertTitle>{{
                t('The draft cannot be published yet')
            }}</AlertTitle>
            <AlertDescription>
                <ul class="list-disc pl-4">
                    <li v-for="message in check.errors" :key="message">
                        {{ message }}
                    </li>
                </ul>
            </AlertDescription>
        </Alert>
        <Alert v-if="check.warnings.length">
            <AlertTitle>{{ t('Warnings') }}</AlertTitle>
            <AlertDescription>
                <ul class="list-disc pl-4">
                    <li v-for="message in check.warnings" :key="message">
                        {{ message }}
                    </li>
                </ul>
            </AlertDescription>
        </Alert>

        <div class="grid gap-6 xl:grid-cols-3">
            <Card class="min-w-0 xl:col-span-2">
                <CardHeader>
                    <CardTitle>{{
                        agent.draft
                            ? t('Draft settings')
                            : t('Published settings (saving creates a draft)')
                    }}</CardTitle>
                </CardHeader>
                <CardContent>
                    <form id="agent-form" @submit.prevent="saveDraft">
                        <AgentSettingsForm :form="form" :options="options" />
                    </form>
                </CardContent>
                <CardFooter
                    class="flex flex-col items-stretch gap-3 border-t pt-6"
                >
                    <div class="grid gap-2">
                        <Label for="notes">{{ t('Change notes') }}</Label>
                        <Input
                            id="notes"
                            v-model="form.notes"
                            form="agent-form"
                            maxlength="1000"
                            :placeholder="t('What changed and why')"
                        />
                        <InputError :message="form.errors.notes" />
                    </div>
                    <div class="flex items-center gap-3">
                        <Button
                            type="submit"
                            form="agent-form"
                            :disabled="form.processing"
                        >
                            <Save class="size-4" />
                            {{ t('Save draft') }}
                        </Button>
                        <span
                            v-if="form.isDirty"
                            class="text-xs text-muted-foreground"
                            >{{ t('Unsaved changes') }}</span
                        >
                        <span
                            v-if="Object.keys(form.errors).length"
                            class="text-xs text-destructive"
                            >{{ t('Review the highlighted fields.') }}</span
                        >
                    </div>
                </CardFooter>
            </Card>

            <div class="grid content-start gap-6">
                <AgentTestPanel
                    :agent-id="agent.id"
                    :testing-draft="agent.draft !== null"
                />
                <AgentVersions :agent-id="agent.id" :versions="versions" />
            </div>
        </div>

        <Dialog v-model:open="publishOpen">
            <DialogContent class="sm:max-w-md">
                <form class="grid gap-4" @submit.prevent="publish">
                    <DialogHeader>
                        <DialogTitle
                            >{{ t('Publish version') }} v{{
                                agent.draft?.number
                            }}</DialogTitle
                        >
                        <DialogDescription>{{
                            t(
                                'New messages will use this version. The current published version is archived and can be restored later.',
                            )
                        }}</DialogDescription>
                    </DialogHeader>
                    <div class="grid gap-2">
                        <Label for="publish-notes">{{
                            t('Change notes')
                        }}</Label>
                        <Textarea
                            id="publish-notes"
                            v-model="publishForm.notes"
                            rows="2"
                        />
                        <InputError
                            :message="
                                publishForm.errors.notes ??
                                (publishForm.errors as Record<string, string>)
                                    .draft
                            "
                        />
                    </div>
                    <DialogFooter>
                        <Button
                            type="submit"
                            :disabled="publishForm.processing"
                            >{{ t('Publish') }}</Button
                        >
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>

        <Dialog v-model:open="detailsOpen">
            <DialogContent class="sm:max-w-md">
                <form class="grid gap-4" @submit.prevent="saveDetails">
                    <DialogHeader>
                        <DialogTitle>{{ t('Agent details') }}</DialogTitle>
                    </DialogHeader>
                    <div class="grid gap-2">
                        <Label for="details-name">{{ t('Name') }}</Label>
                        <Input
                            id="details-name"
                            v-model="details.name"
                            required
                        />
                        <InputError :message="details.errors.name" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="details-description">{{
                            t('Description')
                        }}</Label>
                        <Textarea
                            id="details-description"
                            v-model="details.description"
                            rows="2"
                        />
                    </div>
                    <div class="flex items-center justify-between gap-4">
                        <Label for="details-active">{{ t('Active') }}</Label>
                        <Switch
                            id="details-active"
                            v-model="details.is_active"
                        />
                    </div>
                    <div class="flex items-center justify-between gap-4">
                        <Label for="details-default">{{
                            t('Default agent')
                        }}</Label>
                        <Switch
                            id="details-default"
                            v-model="details.is_default"
                        />
                    </div>
                    <DialogFooter class="sm:justify-between">
                        <ConfirmDialog
                            v-if="!agent.is_default"
                            :title="t('Delete this agent?')"
                            :description="
                                t(
                                    'Its versions are deleted. Recorded usage keeps its history without the version link.',
                                )
                            "
                            :confirm-text="t('Delete')"
                            @confirm="destroy"
                        >
                            <Button type="button" variant="ghost">
                                <Trash2 class="size-4" />
                                {{ t('Delete') }}
                            </Button>
                        </ConfirmDialog>
                        <Button type="submit" :disabled="details.processing">{{
                            t('Save')
                        }}</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    </div>
</template>
