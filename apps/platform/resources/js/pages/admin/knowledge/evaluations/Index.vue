<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ClipboardCheck, Plus } from '@lucide/vue';
import { ref } from 'vue';
import EvaluationSetDialog from '@/components/knowledge/EvaluationSetDialog.vue';
import PageHeader from '@/components/PageHeader.vue';
import { Badge } from '@starter/ui/components/badge';
import { Button } from '@starter/ui/components/button';
import { Card, CardContent } from '@starter/ui/components/card';
import {
    Table,
    TableBody,
    TableCell,
    TableEmpty,
    TableHead,
    TableHeader,
    TableRow,
} from '@starter/ui/components/table';
import { formatDate, formatNumber, t } from '@/lib/i18n';
import {
    evaluationStatusLabels,
    evaluationStatusVariant,
    formatRatio,
} from '@/lib/knowledge';
import knowledge from '@/routes/admin/knowledge';
import evaluations from '@/routes/admin/knowledge/evaluations';
import type { EvaluationSetSummary } from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Knowledge', href: knowledge.index() },
            { title: 'Evaluations', href: evaluations.index() },
        ],
    },
});

defineProps<{
    sets: EvaluationSetSummary[];
    collections: { id: number; name: string }[];
}>();

const creating = ref(false);
</script>

<template>
    <Head :title="t('Evaluations')" />

    <div class="flex flex-col gap-6 p-4 md:p-6">
        <PageHeader
            :title="t('Evaluations')"
            :description="
                t(
                    'Measure the retrieval quality with labelled questions: recall@k, MRR, hit rate, results without passages, latency and cost. They measure the search, not the generated answers (that is the users feedback).',
                )
            "
        >
            <template #icon><ClipboardCheck class="size-5" /></template>
            <Button data-test="new-set" @click="creating = true">
                <Plus class="size-4" />
                {{ t('New set') }}
            </Button>
        </PageHeader>

        <Card class="py-0">
            <CardContent class="px-0">
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>{{ t('Set') }}</TableHead>
                            <TableHead class="text-right">{{
                                t('Questions')
                            }}</TableHead>
                            <TableHead>{{ t('Latest run') }}</TableHead>
                            <TableHead class="text-right">Recall@k</TableHead>
                            <TableHead class="text-right">MRR</TableHead>
                            <TableHead class="text-right">{{
                                t('Hit rate')
                            }}</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        <TableRow v-for="set in sets" :key="set.id">
                            <TableCell class="min-w-0">
                                <Link
                                    :href="evaluations.show(set.id)"
                                    class="font-medium hover:underline"
                                    :data-test="`set-${set.id}`"
                                    >{{ set.name }}</Link
                                >
                                <p class="text-xs text-muted-foreground">
                                    {{ set.collections.join(', ') }}
                                </p>
                            </TableCell>
                            <TableCell class="text-right tabular-nums">{{
                                formatNumber(set.items)
                            }}</TableCell>
                            <TableCell>
                                <template v-if="set.latest">
                                    <Badge
                                        :variant="
                                            evaluationStatusVariant[
                                                set.latest.status
                                            ]
                                        "
                                        >{{
                                            t(
                                                evaluationStatusLabels[
                                                    set.latest.status
                                                ],
                                            )
                                        }}</Badge
                                    >
                                    <span
                                        class="ml-2 text-xs text-muted-foreground"
                                        >{{
                                            formatDate(set.latest.created_at)
                                        }}</span
                                    >
                                </template>
                                <span
                                    v-else
                                    class="text-xs text-muted-foreground"
                                    >{{ t('Never run') }}</span
                                >
                            </TableCell>
                            <TableCell class="text-right tabular-nums">{{
                                formatRatio(set.latest?.metrics?.recall_at_k)
                            }}</TableCell>
                            <TableCell class="text-right tabular-nums">{{
                                set.latest?.metrics
                                    ? set.latest.metrics.mrr.toFixed(3)
                                    : '—'
                            }}</TableCell>
                            <TableCell class="text-right tabular-nums">{{
                                formatRatio(set.latest?.metrics?.hit_rate)
                            }}</TableCell>
                        </TableRow>
                        <TableEmpty v-if="!sets.length" :colspan="6">{{
                            t(
                                'No evaluation sets yet. Create one and add questions, or save them from the retrieval lab.',
                            )
                        }}</TableEmpty>
                    </TableBody>
                </Table>
            </CardContent>
        </Card>

        <EvaluationSetDialog
            v-model:open="creating"
            :collections="collections"
        />
    </div>
</template>
