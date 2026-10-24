<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { CirclePause, RotateCcw, Square } from '@lucide/vue';
import { ref } from 'vue';
import InputError from '@/components/InputError.vue';
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
import { Progress } from '@starter/ui/components/progress';
import {
    Table,
    TableBody,
    TableCell,
    TableEmpty,
    TableHead,
    TableHeader,
    TableRow,
} from '@starter/ui/components/table';
import { formatCost } from '@/lib/ai';
import { formatDate, formatNumber, t } from '@/lib/i18n';
import {
    formatMs,
    isOpenJob,
    jobStatusLabels,
    jobStatusVariant,
    jobTypeLabels,
    stageLabels,
} from '@/lib/knowledge';
import jobRoutes from '@/routes/admin/knowledge/jobs';
import type { RagJobSummary } from '@/types';

withDefaults(defineProps<{ jobs: RagJobSummary[]; canManage?: boolean }>(), {
    canManage: false,
});

const resuming = ref<RagJobSummary | null>(null);
const maxCost = ref('1');
const errors = ref<Record<string, string>>({});

const percent = (job: RagJobSummary) =>
    job.progress_total
        ? Math.round((job.progress_done / job.progress_total) * 100)
        : null;

const totalTime = (job: RagJobSummary) =>
    Object.values(job.timings ?? {}).reduce((sum, ms) => sum + ms, 0);

const timingTitle = (job: RagJobSummary) =>
    Object.entries(job.timings ?? {})
        .map(
            ([stage, ms]) =>
                `${t(stageLabels[stage] ?? stage)}: ${formatMs(ms)}`,
        )
        .join(' · ');

const post = (url: string, data: Record<string, string | number> = {}) =>
    router.post(url, data, {
        preserveScroll: true,
        onError: (bag) => (errors.value = bag),
        onSuccess: () => {
            errors.value = {};
            resuming.value = null;
        },
    });

const openResume = (job: RagJobSummary) => {
    resuming.value = job;
    maxCost.value = job.estimate?.cost
        ? String(
              Math.max(
                  0.01,
                  Math.ceil(Number(job.estimate.cost) * 1.2 * 100) / 100,
              ),
          )
        : '1';
};
</script>

<template>
    <div class="overflow-x-auto rounded-lg border">
        <Table>
            <TableHeader>
                <TableRow>
                    <TableHead>{{ t('Job') }}</TableHead>
                    <TableHead>{{ t('Status') }}</TableHead>
                    <TableHead class="min-w-40">{{ t('Progress') }}</TableHead>
                    <TableHead>{{ t('Units') }}</TableHead>
                    <TableHead>{{ t('Cost') }}</TableHead>
                    <TableHead>{{ t('Created') }}</TableHead>
                    <TableHead v-if="canManage" class="text-right" />
                </TableRow>
            </TableHeader>
            <TableBody>
                <TableRow
                    v-for="job in jobs"
                    :key="job.id"
                    :data-test="`job-${job.type}-${job.status}`"
                >
                    <TableCell class="align-top">
                        <div class="font-medium">
                            {{ t(jobTypeLabels[job.type] ?? job.type) }}
                        </div>
                        <div class="text-xs text-muted-foreground">
                            {{
                                t(
                                    job.trigger === 'schedule'
                                        ? 'Scheduled'
                                        : job.trigger === 'sync'
                                          ? 'From a sync'
                                          : job.trigger === 'reindex'
                                            ? 'From a reindex'
                                            : 'Manual',
                                )
                            }}
                            · {{ t('attempt :n', { n: job.attempts }) }}
                        </div>
                    </TableCell>
                    <TableCell class="align-top">
                        <Badge :variant="jobStatusVariant[job.status]">{{
                            t(jobStatusLabels[job.status] ?? job.status)
                        }}</Badge>
                        <p
                            v-if="job.error"
                            class="mt-1 max-w-72 text-xs text-muted-foreground"
                            :class="{
                                'text-destructive': job.status === 'failed',
                            }"
                        >
                            {{ job.error }}
                        </p>
                        <p
                            v-for="warning in job.warnings"
                            :key="warning"
                            class="mt-1 max-w-72 text-xs text-amber-600 dark:text-amber-400"
                        >
                            {{ warning }}
                        </p>
                    </TableCell>
                    <TableCell class="align-top">
                        <div class="grid gap-1.5">
                            <span class="text-xs text-muted-foreground">
                                {{
                                    job.stage
                                        ? t(stageLabels[job.stage] ?? job.stage)
                                        : '—'
                                }}
                                <template v-if="job.progress_total !== null">
                                    · {{ job.progress_done }}/{{
                                        job.progress_total
                                    }}
                                </template>
                                <template v-else-if="isOpenJob(job.status)">
                                    · {{ t('unknown total') }}
                                </template>
                            </span>
                            <Progress
                                v-if="percent(job) !== null"
                                :model-value="percent(job)"
                                class="h-1.5"
                            />
                            <span
                                v-if="totalTime(job)"
                                class="text-xs text-muted-foreground"
                                :title="timingTitle(job)"
                                >{{ formatMs(totalTime(job)) }}</span
                            >
                        </div>
                    </TableCell>
                    <TableCell class="align-top text-xs text-muted-foreground">
                        <div v-for="(value, key) in job.units" :key="key">
                            {{ t(String(key)) }}: {{ formatNumber(value) }}
                        </div>
                        <div v-if="job.estimate?.tokens">
                            {{ t('Estimate') }}:
                            {{ formatNumber(job.estimate.tokens) }} tokens ·
                            {{ formatCost(job.estimate.cost ?? null) }}
                        </div>
                    </TableCell>
                    <TableCell class="align-top tabular-nums">
                        {{ formatCost(job.cost) }}
                        <div
                            v-if="job.max_cost"
                            class="text-xs text-muted-foreground"
                        >
                            {{
                                t('max :amount', {
                                    amount: formatCost(job.max_cost),
                                })
                            }}
                        </div>
                    </TableCell>
                    <TableCell
                        class="align-top text-xs whitespace-nowrap text-muted-foreground"
                        >{{ formatDate(job.created_at) }}</TableCell
                    >
                    <TableCell v-if="canManage" class="text-right align-top">
                        <div class="flex justify-end gap-1">
                            <Button
                                v-if="job.status === 'paused'"
                                size="sm"
                                variant="outline"
                                data-test="job-resume"
                                @click="openResume(job)"
                            >
                                <CirclePause class="size-4" />
                                {{ t('Confirm budget') }}
                            </Button>
                            <Button
                                v-if="isOpenJob(job.status)"
                                size="icon"
                                variant="ghost"
                                :aria-label="t('Cancel')"
                                @click="post(jobRoutes.cancel(job.id).url)"
                            >
                                <Square class="size-4" />
                            </Button>
                            <Button
                                v-if="
                                    ['failed', 'cancelled'].includes(
                                        job.status,
                                    ) || job.error_type === 'partial'
                                "
                                size="icon"
                                variant="ghost"
                                :aria-label="t('Retry')"
                                @click="post(jobRoutes.retry(job.id).url)"
                            >
                                <RotateCcw class="size-4" />
                            </Button>
                        </div>
                    </TableCell>
                </TableRow>
                <TableEmpty v-if="!jobs.length" :colspan="canManage ? 7 : 6">{{
                    t('No jobs yet.')
                }}</TableEmpty>
            </TableBody>
        </Table>
    </div>

    <Dialog
        :open="resuming !== null"
        @update:open="(value) => !value && (resuming = null)"
    >
        <DialogContent>
            <DialogHeader>
                <DialogTitle>{{
                    t('Confirm the ingestion budget')
                }}</DialogTitle>
                <DialogDescription>
                    {{
                        t(
                            'The embeddings stop when the confirmed maximum is reached. The estimate uses about 4 characters per token.',
                        )
                    }}
                </DialogDescription>
            </DialogHeader>
            <div v-if="resuming" class="grid gap-3 text-sm">
                <div
                    v-if="resuming.estimate"
                    class="grid grid-cols-3 gap-2 rounded-md border p-3"
                >
                    <div>
                        <div class="text-xs text-muted-foreground">
                            {{ t('Documents') }}
                        </div>
                        {{ formatNumber(resuming.estimate.documents ?? 0) }}
                    </div>
                    <div>
                        <div class="text-xs text-muted-foreground">Tokens</div>
                        {{ formatNumber(resuming.estimate.tokens ?? 0) }}
                    </div>
                    <div>
                        <div class="text-xs text-muted-foreground">
                            {{ t('Estimated cost') }}
                        </div>
                        {{ formatCost(resuming.estimate.cost ?? null) }}
                    </div>
                </div>
                <div class="grid gap-2">
                    <Label for="max-cost">{{ t('Maximum cost (USD)') }}</Label>
                    <Input
                        id="max-cost"
                        v-model="maxCost"
                        type="number"
                        min="0"
                        step="0.01"
                    />
                    <InputError :message="errors.max_cost ?? errors.job" />
                </div>
            </div>
            <DialogFooter>
                <Button variant="outline" @click="resuming = null">{{
                    t('Cancel')
                }}</Button>
                <Button
                    data-test="job-resume-confirm"
                    @click="
                        resuming &&
                        post(jobRoutes.resume(resuming.id).url, {
                            max_cost: Number(maxCost),
                        })
                    "
                    >{{ t('Confirm and continue') }}</Button
                >
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
