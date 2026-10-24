<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { FlaskConical, Plus, Trash2 } from '@lucide/vue';
import InputError from '@/components/InputError.vue';
import PageHeader from '@/components/PageHeader.vue';
import { Badge } from '@starter/ui/components/badge';
import { Button } from '@starter/ui/components/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@starter/ui/components/card';
import { Checkbox } from '@starter/ui/components/checkbox';
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
import { formatCost } from '@/lib/ai';
import { formatDate, t } from '@/lib/i18n';
import lab from '@/routes/admin/ai/lab';
import type { LabModelOption, LabRunSummary } from '@/types';

defineOptions({
    layout: { breadcrumbs: [{ title: 'Lab', href: lab.index() }] },
});

defineProps<{ runs: LabRunSummary[]; models: LabModelOption[] }>();

const form = useForm({
    name: '',
    prompts: [''] as string[],
    system_prompt: '',
    model_ids: [] as number[],
    max_tokens: 512 as number | string,
    max_cost: 0.5 as number | string,
    max_concurrency: 2 as number | string,
});

const statusVariant: Record<
    string,
    'default' | 'secondary' | 'outline' | 'destructive'
> = {
    queued: 'outline',
    running: 'secondary',
    completed: 'default',
    cancelled: 'outline',
};

const statusLabels: Record<string, string> = {
    queued: 'Queued',
    running: 'Running',
    completed: 'Completed',
    cancelled: 'Cancelled',
};

const toggleModel = (id: number, checked: boolean | string) => {
    form.model_ids = checked
        ? [...new Set([...form.model_ids, id])]
        : form.model_ids.filter((item) => item !== id);
};

const error = (key: string) =>
    (form.errors as Record<string, string | undefined>)[key];

const submit = () =>
    form
        .transform((data) => ({
            ...data,
            prompts: data.prompts
                .map((prompt) => prompt.trim())
                .filter(Boolean),
            system_prompt: data.system_prompt || null,
            max_tokens: data.max_tokens === '' ? null : Number(data.max_tokens),
            max_cost: Number(data.max_cost),
            max_concurrency: Number(data.max_concurrency),
        }))
        .post(lab.store().url);
</script>

<template>
    <Head :title="t('Lab')" />

    <div class="flex flex-col gap-6 p-4 md:p-6">
        <PageHeader
            :title="t('Lab')"
            :description="
                t(
                    'Run the same prompts against several models under a maximum cost and concurrency, and compare answers, errors, latency, time to first token, tokens and cost.',
                )
            "
        />

        <div class="grid gap-6 xl:grid-cols-5">
            <Card class="min-w-0 xl:col-span-2">
                <CardHeader>
                    <CardTitle class="flex items-center gap-2">
                        <FlaskConical class="size-4" />
                        {{ t('New run') }}
                    </CardTitle>
                    <CardDescription>{{
                        t(
                            'Every call is recorded in the ledger with the "lab" feature.',
                        )
                    }}</CardDescription>
                </CardHeader>
                <CardContent>
                    <form class="grid gap-4" @submit.prevent="submit">
                        <div class="grid gap-2">
                            <Label for="lab-name">{{ t('Name') }}</Label>
                            <Input id="lab-name" v-model="form.name" required />
                            <InputError :message="form.errors.name" />
                        </div>
                        <div class="grid gap-2">
                            <Label>{{ t('Prompts (up to 10)') }}</Label>
                            <div
                                v-for="(_, index) in form.prompts"
                                :key="index"
                                class="flex items-start gap-2"
                            >
                                <Textarea
                                    v-model="form.prompts[index]"
                                    rows="2"
                                />
                                <Button
                                    v-if="form.prompts.length > 1"
                                    type="button"
                                    variant="ghost"
                                    size="icon"
                                    :aria-label="t('Remove')"
                                    @click="form.prompts.splice(index, 1)"
                                >
                                    <Trash2 class="size-4" />
                                </Button>
                            </div>
                            <Button
                                v-if="form.prompts.length < 10"
                                type="button"
                                variant="outline"
                                size="sm"
                                @click="form.prompts.push('')"
                            >
                                <Plus class="size-4" />
                                {{ t('Add prompt') }}
                            </Button>
                            <InputError
                                :message="
                                    error('prompts') ?? error('prompts.0')
                                "
                            />
                        </div>
                        <div class="grid gap-2">
                            <Label for="lab-system">{{
                                t('System prompt (optional)')
                            }}</Label>
                            <Textarea
                                id="lab-system"
                                v-model="form.system_prompt"
                                rows="2"
                            />
                        </div>
                        <div class="grid gap-2">
                            <Label>{{ t('Models (up to 6)') }}</Label>
                            <Label
                                v-for="model in models"
                                :key="model.id"
                                class="flex items-center gap-2 font-normal"
                            >
                                <Checkbox
                                    :model-value="
                                        form.model_ids.includes(model.id)
                                    "
                                    :disabled="
                                        !form.model_ids.includes(model.id) &&
                                        form.model_ids.length >= 6
                                    "
                                    @update:model-value="
                                        (checked) =>
                                            toggleModel(model.id, checked)
                                    "
                                />
                                {{ model.label }}
                                <span class="text-xs text-muted-foreground">{{
                                    model.connection
                                }}</span>
                            </Label>
                            <InputError :message="error('model_ids')" />
                        </div>
                        <div class="grid grid-cols-3 items-end gap-3">
                            <div class="grid gap-2">
                                <Label for="lab-cost">{{
                                    t('Max cost (USD)')
                                }}</Label>
                                <Input
                                    id="lab-cost"
                                    v-model="form.max_cost"
                                    type="number"
                                    min="0"
                                    step="any"
                                />
                            </div>
                            <div class="grid gap-2">
                                <Label for="lab-concurrency">{{
                                    t('Concurrency')
                                }}</Label>
                                <Input
                                    id="lab-concurrency"
                                    v-model="form.max_concurrency"
                                    type="number"
                                    min="1"
                                    max="4"
                                />
                            </div>
                            <div class="grid gap-2">
                                <Label for="lab-tokens">{{
                                    t('Max tokens')
                                }}</Label>
                                <Input
                                    id="lab-tokens"
                                    v-model="form.max_tokens"
                                    type="number"
                                    min="16"
                                    max="8000"
                                />
                            </div>
                        </div>
                        <InputError
                            :message="
                                form.errors.max_cost ??
                                form.errors.max_concurrency ??
                                form.errors.max_tokens
                            "
                        />
                        <Button type="submit" :disabled="form.processing">{{
                            t('Start run')
                        }}</Button>
                    </form>
                </CardContent>
            </Card>

            <Card class="min-w-0 xl:col-span-3">
                <CardHeader>
                    <CardTitle>{{ t('Runs') }}</CardTitle>
                </CardHeader>
                <CardContent class="overflow-x-auto">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>{{ t('Name') }}</TableHead>
                                <TableHead>{{ t('Status') }}</TableHead>
                                <TableHead>{{ t('Results') }}</TableHead>
                                <TableHead>{{ t('Spent') }}</TableHead>
                                <TableHead>{{ t('Date') }}</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            <TableRow v-for="run in runs" :key="run.id">
                                <TableCell>
                                    <Link
                                        :href="lab.show(run.id)"
                                        class="font-medium hover:underline"
                                        >{{ run.name }}</Link
                                    >
                                </TableCell>
                                <TableCell>
                                    <Badge
                                        :variant="statusVariant[run.status]"
                                        >{{
                                            t(
                                                statusLabels[run.status] ??
                                                    run.status,
                                            )
                                        }}</Badge
                                    >
                                </TableCell>
                                <TableCell class="text-sm">
                                    {{ run.counts.succeeded }}/{{
                                        run.counts.total
                                    }}
                                    <span
                                        v-if="run.counts.failed"
                                        class="text-destructive"
                                        >· {{ run.counts.failed }}
                                        {{ t('failed') }}</span
                                    >
                                    <span
                                        v-if="run.counts.skipped"
                                        class="text-muted-foreground"
                                        >· {{ run.counts.skipped }}
                                        {{ t('skipped') }}</span
                                    >
                                </TableCell>
                                <TableCell class="text-sm whitespace-nowrap"
                                    >{{ formatCost(run.spent) }} /
                                    {{ formatCost(run.max_cost) }}</TableCell
                                >
                                <TableCell
                                    class="text-sm text-muted-foreground"
                                    >{{ formatDate(run.created_at) }}</TableCell
                                >
                            </TableRow>
                            <TableEmpty v-if="!runs.length" :colspan="5">{{
                                t('No runs yet.')
                            }}</TableEmpty>
                        </TableBody>
                    </Table>
                </CardContent>
            </Card>
        </div>
    </div>
</template>
