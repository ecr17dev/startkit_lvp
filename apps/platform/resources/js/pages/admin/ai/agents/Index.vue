<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { Bot, Plus, Star } from '@lucide/vue';
import { ref, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import PageHeader from '@/components/PageHeader.vue';
import { Badge } from '@starter/ui/components/badge';
import { Button } from '@starter/ui/components/button';
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
import {
    Table,
    TableBody,
    TableCell,
    TableEmpty,
    TableHead,
    TableHeader,
    TableRow,
} from '@starter/ui/components/table';
import { Textarea } from '@starter/ui/components/textarea';
import { formatDate, t } from '@/lib/i18n';
import agentRoutes from '@/routes/admin/ai/agents';
import type { AgentSummary } from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Agents', href: agentRoutes.index() }],
    },
});

defineProps<{ agents: AgentSummary[] }>();

const open = ref(false);
const form = useForm({ name: '', slug: '', description: '' });
const slugTouched = ref(false);

watch(
    () => form.name,
    (name) => {
        if (!slugTouched.value) {
            form.slug = name
                .toLowerCase()
                .normalize('NFD')
                .replace(/[̀-ͯ]/g, '')
                .replace(/[^a-z0-9]+/g, '-')
                .replace(/^-+|-+$/g, '')
                .slice(0, 80);
        }
    },
);

const submit = () =>
    form.post(agentRoutes.store().url, {
        onSuccess: () => {
            open.value = false;
            form.reset();
            slugTouched.value = false;
        },
    });
</script>

<template>
    <Head :title="t('Agents')" />

    <div class="flex flex-col gap-6 p-4 md:p-6">
        <PageHeader
            :title="t('Agents')"
            :description="
                t(
                    'Each agent has a draft and published versions. Conversations always use the published version the user is allowed to use.',
                )
            "
        >
            <Button @click="open = true">
                <Plus class="size-4" />
                {{ t('New agent') }}
            </Button>
        </PageHeader>

        <div class="overflow-x-auto rounded-lg border">
            <Table>
                <TableHeader>
                    <TableRow>
                        <TableHead>{{ t('Agent') }}</TableHead>
                        <TableHead>{{ t('Published') }}</TableHead>
                        <TableHead>{{ t('Draft') }}</TableHead>
                        <TableHead>{{ t('Tools') }}</TableHead>
                        <TableHead>{{ t('Access') }}</TableHead>
                        <TableHead class="text-right" />
                    </TableRow>
                </TableHeader>
                <TableBody>
                    <TableRow v-for="agent in agents" :key="agent.id">
                        <TableCell>
                            <div class="flex items-center gap-3">
                                <span
                                    class="flex size-9 shrink-0 items-center justify-center rounded-full bg-muted text-lg"
                                >
                                    <template v-if="agent.avatar">{{
                                        agent.avatar
                                    }}</template>
                                    <Bot v-else class="size-4" />
                                </span>
                                <div class="grid gap-0.5">
                                    <span
                                        class="flex items-center gap-1.5 font-medium"
                                    >
                                        {{ agent.name }}
                                        <Star
                                            v-if="agent.is_default"
                                            class="size-3.5 fill-amber-400 text-amber-400"
                                            :aria-label="t('Default agent')"
                                        />
                                        <Badge
                                            v-if="!agent.is_active"
                                            variant="outline"
                                            >{{ t('Inactive') }}</Badge
                                        >
                                    </span>
                                    <span
                                        class="text-xs text-muted-foreground"
                                        >{{
                                            agent.description ?? agent.slug
                                        }}</span
                                    >
                                </div>
                            </div>
                        </TableCell>
                        <TableCell>
                            <template v-if="agent.published_version">
                                <Badge
                                    >v{{
                                        agent.published_version.number
                                    }}</Badge
                                >
                                <div class="mt-1 text-xs text-muted-foreground">
                                    {{
                                        formatDate(
                                            agent.published_version
                                                .published_at,
                                        )
                                    }}
                                </div>
                            </template>
                            <Badge v-else variant="outline">{{
                                t('Not published')
                            }}</Badge>
                        </TableCell>
                        <TableCell>
                            <Badge v-if="agent.draft" variant="secondary"
                                >v{{ agent.draft.number }}</Badge
                            >
                            <span v-else class="text-muted-foreground">—</span>
                        </TableCell>
                        <TableCell>{{ agent.tools.length }}</TableCell>
                        <TableCell class="text-sm">
                            {{
                                agent.roles.length
                                    ? agent.roles.join(', ')
                                    : t('Everyone with chat access')
                            }}
                        </TableCell>
                        <TableCell class="text-right">
                            <Button variant="outline" size="sm" as-child>
                                <Link :href="agentRoutes.edit(agent.id)">{{
                                    t('Configure')
                                }}</Link>
                            </Button>
                        </TableCell>
                    </TableRow>
                    <TableEmpty v-if="!agents.length" :colspan="6">
                        {{ t('No agents yet.') }}
                    </TableEmpty>
                </TableBody>
            </Table>
        </div>

        <Dialog v-model:open="open">
            <DialogContent class="sm:max-w-lg">
                <form class="grid gap-4" @submit.prevent="submit">
                    <DialogHeader>
                        <DialogTitle>{{ t('New agent') }}</DialogTitle>
                        <DialogDescription>{{
                            t(
                                'The agent starts as a draft with default settings. Configure and publish it to make it available.',
                            )
                        }}</DialogDescription>
                    </DialogHeader>
                    <div class="grid gap-2">
                        <Label for="agent-name">{{ t('Name') }}</Label>
                        <Input id="agent-name" v-model="form.name" required />
                        <InputError :message="form.errors.name" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="agent-slug">{{ t('Identifier') }}</Label>
                        <Input
                            id="agent-slug"
                            v-model="form.slug"
                            required
                            @input="slugTouched = true"
                        />
                        <p class="text-xs text-muted-foreground">
                            {{ t('Lowercase letters, numbers and dashes.') }}
                        </p>
                        <InputError :message="form.errors.slug" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="agent-description">{{
                            t('Description')
                        }}</Label>
                        <Textarea
                            id="agent-description"
                            v-model="form.description"
                            rows="2"
                        />
                        <InputError :message="form.errors.description" />
                    </div>
                    <DialogFooter>
                        <Button type="submit" :disabled="form.processing">{{
                            t('Create agent')
                        }}</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    </div>
</template>
