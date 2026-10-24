<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { CircleX } from '@lucide/vue';
import { computed, onBeforeUnmount, onMounted } from 'vue';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import PageHeader from '@/components/PageHeader.vue';
import { Badge } from '@starter/ui/components/badge';
import { Button } from '@starter/ui/components/button';
import {
    Card,
    CardContent,
    CardHeader,
    CardTitle,
} from '@starter/ui/components/card';
import { Progress } from '@starter/ui/components/progress';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@starter/ui/components/table';
import { formatCost } from '@/lib/ai';
import { formatNumber, t } from '@/lib/i18n';
import lab from '@/routes/admin/ai/lab';
import type { LabResultRow, LabRunDetail } from '@/types';

const props = defineProps<{ run: LabRunDetail }>();

defineOptions({
    layout: (props: { run: LabRunDetail }) => ({
        breadcrumbs: [
            { title: 'Lab', href: lab.index() },
            { title: props.run.name, href: lab.show(props.run.id) },
        ],
    }),
});

const active = computed(() => ['queued', 'running'].includes(props.run.status));
const progress = computed(() =>
    props.run.counts.total
        ? Math.round(
              ((props.run.counts.total - props.run.counts.pending) /
                  props.run.counts.total) *
                  100,
          )
        : 0,
);

let timer: ReturnType<typeof setInterval> | undefined;

// Follow the queue workers while the run is active...
onMounted(() => {
    timer = setInterval(() => {
        if (active.value) {
            router.reload({ only: ['run'] });
        }
    }, 2000);
});
onBeforeUnmount(() => clearInterval(timer));

const cell = (modelId: number, promptIndex: number): LabResultRow | undefined =>
    props.run.results.find(
        (result) =>
            result.model_id === modelId && result.prompt_index === promptIndex,
    );

const totals = computed(() =>
    props.run.models.map((model) => {
        const rows = props.run.results.filter(
            (result) =>
                result.model_id === model.id && result.status === 'succeeded',
        );
        const average = (values: number[]) =>
            values.length
                ? Math.round(
                      values.reduce((sum, value) => sum + value, 0) /
                          values.length,
                  )
                : null;

        return {
            model,
            latency: average(rows.map((row) => row.latency_ms ?? 0)),
            ttft: average(
                rows
                    .filter((row) => row.ttft_ms !== null)
                    .map((row) => row.ttft_ms ?? 0),
            ),
            tokens: rows.reduce(
                (sum, row) => sum + row.input_tokens + row.output_tokens,
                0,
            ),
            cost: rows.some((row) => row.cost === null)
                ? null
                : rows.reduce((sum, row) => sum + Number(row.cost ?? 0), 0),
            errors: props.run.results.filter(
                (result) =>
                    result.model_id === model.id &&
                    result.status !== 'succeeded',
            ).length,
        };
    }),
);

const statusVariant: Record<
    string,
    'default' | 'secondary' | 'outline' | 'destructive'
> = {
    pending: 'outline',
    running: 'secondary',
    succeeded: 'default',
    failed: 'destructive',
    skipped: 'outline',
};

const statusLabels: Record<string, string> = {
    pending: 'Pending',
    running: 'Running',
    succeeded: 'Succeeded',
    failed: 'Failed',
    skipped: 'Skipped',
    queued: 'Queued',
    completed: 'Completed',
    cancelled: 'Cancelled',
};

const cancel = () =>
    router.post(lab.cancel(props.run.id).url, {}, { preserveScroll: true });
</script>

<template>
    <Head :title="run.name" />

    <div class="flex flex-col gap-6 p-4 md:p-6">
        <PageHeader
            :title="run.name"
            :description="`${t(statusLabels[run.status] ?? run.status)} · ${formatCost(run.spent)} / ${formatCost(run.max_cost)} · ${t('Concurrency')} ${run.max_concurrency}`"
        >
            <ConfirmDialog
                v-if="active"
                :title="t('Cancel this run?')"
                :description="
                    t(
                        'Pending tasks are skipped. Answers already received are kept.',
                    )
                "
                :confirm-text="t('Cancel run')"
                @confirm="cancel"
            >
                <Button variant="outline">
                    <CircleX class="size-4" />
                    {{ t('Cancel run') }}
                </Button>
            </ConfirmDialog>
        </PageHeader>

        <div v-if="active" class="grid gap-2">
            <Progress :model-value="progress" />
            <p class="text-xs text-muted-foreground">
                {{ run.counts.total - run.counts.pending }}/{{
                    run.counts.total
                }}
            </p>
        </div>

        <Card>
            <CardHeader>
                <CardTitle>{{ t('Summary by model') }}</CardTitle>
            </CardHeader>
            <CardContent class="overflow-x-auto">
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>{{ t('Model') }}</TableHead>
                            <TableHead>{{ t('Average latency') }}</TableHead>
                            <TableHead>{{
                                t('Time to first token')
                            }}</TableHead>
                            <TableHead>{{ t('Tokens') }}</TableHead>
                            <TableHead>{{ t('Cost') }}</TableHead>
                            <TableHead>{{ t('Errors or skipped') }}</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        <TableRow v-for="total in totals" :key="total.model.id">
                            <TableCell>
                                <div class="font-medium">
                                    {{ total.model.label }}
                                </div>
                                <div class="text-xs text-muted-foreground">
                                    {{ total.model.connection }}
                                </div>
                            </TableCell>
                            <TableCell>{{
                                total.latency !== null
                                    ? `${formatNumber(total.latency)} ms`
                                    : '—'
                            }}</TableCell>
                            <TableCell>{{
                                total.ttft !== null
                                    ? `${formatNumber(total.ttft)} ms`
                                    : '—'
                            }}</TableCell>
                            <TableCell>{{
                                formatNumber(total.tokens)
                            }}</TableCell>
                            <TableCell>{{ formatCost(total.cost) }}</TableCell>
                            <TableCell>{{ total.errors }}</TableCell>
                        </TableRow>
                    </TableBody>
                </Table>
            </CardContent>
        </Card>

        <Card v-for="(prompt, index) in run.prompts" :key="index">
            <CardHeader>
                <CardTitle class="text-sm font-medium whitespace-pre-wrap"
                    >{{ index + 1 }}. {{ prompt }}</CardTitle
                >
            </CardHeader>
            <CardContent class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                <div
                    v-for="model in run.models"
                    :key="model.id"
                    class="grid content-start gap-2 rounded-lg border p-3"
                >
                    <div class="flex items-center justify-between gap-2">
                        <span class="truncate text-sm font-medium">{{
                            model.label
                        }}</span>
                        <Badge
                            :variant="
                                statusVariant[
                                    cell(model.id, index)?.status ?? 'pending'
                                ]
                            "
                            >{{
                                t(
                                    statusLabels[
                                        cell(model.id, index)?.status ??
                                            'pending'
                                    ],
                                )
                            }}</Badge
                        >
                    </div>
                    <p
                        v-if="cell(model.id, index)?.output"
                        class="max-h-60 overflow-y-auto text-sm whitespace-pre-wrap"
                    >
                        {{ cell(model.id, index)?.output }}
                    </p>
                    <p
                        v-if="cell(model.id, index)?.error"
                        class="text-sm text-destructive"
                    >
                        {{ cell(model.id, index)?.error }}
                    </p>
                    <p
                        v-if="cell(model.id, index)?.status === 'succeeded'"
                        class="text-[11px] text-muted-foreground"
                    >
                        {{ formatNumber(cell(model.id, index)?.latency_ms) }} ms
                        · {{ t('TTFT') }}
                        {{ formatNumber(cell(model.id, index)?.ttft_ms) }} ms ·
                        {{
                            formatNumber(
                                (cell(model.id, index)?.input_tokens ?? 0) +
                                    (cell(model.id, index)?.output_tokens ?? 0),
                            )
                        }}
                        {{ t('tokens') }} ·
                        {{ formatCost(cell(model.id, index)?.cost) }}
                    </p>
                </div>
            </CardContent>
        </Card>
    </div>
</template>
