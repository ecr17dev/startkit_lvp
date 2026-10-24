<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import {
    Check,
    ClipboardCheck,
    Pencil,
    Play,
    Plus,
    Trash2,
    X,
} from '@lucide/vue';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import InputError from '@/components/InputError.vue';
import EvaluationSetDialog from '@/components/knowledge/EvaluationSetDialog.vue';
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
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@starter/ui/components/select';
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
import { formatDate, formatNumber, t } from '@/lib/i18n';
import {
    evaluationStatusLabels,
    evaluationStatusVariant,
    formatMs,
    formatRatio,
    methodLabels,
} from '@/lib/knowledge';
import knowledge from '@/routes/admin/knowledge';
import evaluations from '@/routes/admin/knowledge/evaluations';
import type {
    EvaluationItemResult,
    EvaluationItemRow,
    EvaluationRunSummary,
} from '@/types';

const props = defineProps<{
    set: {
        id: number;
        name: string;
        description: string | null;
        collection_ids: number[];
        collections: string[];
    };
    items: EvaluationItemRow[];
    runs: EvaluationRunSummary[];
    current:
        | (EvaluationRunSummary & {
              results: Record<number, EvaluationItemResult>;
          })
        | null;
    collections: { id: number; name: string }[];
    documents: { id: number; title: string; collection: string }[];
}>();

defineOptions({
    layout: (props: { set: { id: number; name: string } }) => ({
        breadcrumbs: [
            { title: 'Knowledge', href: knowledge.index() },
            { title: 'Evaluations', href: evaluations.index() },
            { title: props.set.name, href: evaluations.show(props.set.id) },
        ],
    }),
});

const editing = ref(false);

// Run with retrieval settings; empty fields use the collection settings...
const runForm = useForm({
    label: '',
    method: 'default',
    top_k: '' as number | string,
    min_score: '' as number | string,
    max_context_tokens: '' as number | string,
});

const startRun = () =>
    runForm
        .transform((data) => ({
            label: data.label || null,
            method: data.method === 'default' ? null : data.method,
            top_k: data.top_k === '' ? null : Number(data.top_k),
            min_score: data.min_score === '' ? null : Number(data.min_score),
            max_context_tokens:
                data.max_context_tokens === ''
                    ? null
                    : Number(data.max_context_tokens),
        }))
        .post(evaluations.run(props.set.id).url, { preserveScroll: true });

const itemForm = useForm({
    question: '',
    expected_answer: '',
    expected_document_ids: [] as number[],
});
const filter = ref('');

const filteredDocuments = computed(() => {
    const needle = filter.value.trim().toLowerCase();

    return needle === ''
        ? props.documents
        : props.documents.filter((document) =>
              document.title.toLowerCase().includes(needle),
          );
});

const toggleDocument = (id: number, checked: boolean | string) => {
    itemForm.expected_document_ids = checked
        ? [...new Set([...itemForm.expected_document_ids, id])]
        : itemForm.expected_document_ids.filter((item) => item !== id);
};

const addItem = () =>
    itemForm
        .transform((data) => ({
            ...data,
            expected_answer: data.expected_answer || null,
        }))
        .post(evaluations.items.store(props.set.id).url, {
            preserveScroll: true,
            onSuccess: () => itemForm.reset(),
        });

const removeItem = (id: number) =>
    router.delete(
        evaluations.items.destroy({ set: props.set.id, item: id }).url,
        { preserveScroll: true },
    );

const removeSet = () => router.delete(evaluations.destroy(props.set.id).url);

const settingsSummary = (run: EvaluationRunSummary) =>
    [
        run.settings.method
            ? t(methodLabels[run.settings.method] ?? run.settings.method)
            : t('Collection settings'),
        run.settings.top_k ? `k=${run.settings.top_k}` : null,
        run.settings.min_score !== undefined
            ? `min ${run.settings.min_score}`
            : null,
    ]
        .filter(Boolean)
        .join(' · ');

const resultFor = (item: EvaluationItemRow) =>
    props.current?.results?.[item.id] ?? null;

const isExpected = (item: EvaluationItemRow, id: number) =>
    item.expected.some((document) => document.id === id);

// Refresh while a run is queued or running...
let timer: ReturnType<typeof setInterval> | undefined;

onMounted(() => {
    timer = setInterval(() => {
        if (
            props.runs.some(
                (run) => run.status === 'queued' || run.status === 'running',
            )
        ) {
            router.reload({ only: ['runs', 'current'] });
        }
    }, 2000);
});
onBeforeUnmount(() => clearInterval(timer));
</script>

<template>
    <Head :title="set.name" />

    <div class="flex flex-col gap-6 p-4 md:p-6">
        <PageHeader
            :title="set.name"
            :description="
                set.description ??
                t('Collections: :list', { list: set.collections.join(', ') })
            "
        >
            <template #icon><ClipboardCheck class="size-5" /></template>
            <Button variant="outline" @click="editing = true">
                <Pencil class="size-4" />
                {{ t('Edit') }}
            </Button>
            <ConfirmDialog
                :title="t('Delete evaluation set?')"
                :description="
                    t('Its questions and the results of its runs are deleted.')
                "
                @confirm="removeSet"
            >
                <Button variant="outline" :aria-label="t('Delete')">
                    <Trash2 class="size-4" />
                </Button>
            </ConfirmDialog>
        </PageHeader>

        <Card class="min-w-0 py-0">
            <CardHeader class="pt-6">
                <CardTitle>{{ t('Runs') }}</CardTitle>
                <CardDescription>{{
                    t(
                        'Compare settings: recall@k is the share of expected documents found; MRR rewards finding the first one early.',
                    )
                }}</CardDescription>
            </CardHeader>
            <CardContent class="px-0">
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>{{ t('Run') }}</TableHead>
                            <TableHead>{{ t('Status') }}</TableHead>
                            <TableHead class="text-right">Recall@k</TableHead>
                            <TableHead class="text-right">MRR</TableHead>
                            <TableHead class="text-right">{{
                                t('Hit rate')
                            }}</TableHead>
                            <TableHead class="text-right">{{
                                t('No results')
                            }}</TableHead>
                            <TableHead class="text-right">p95</TableHead>
                            <TableHead class="text-right">{{
                                t('Cost')
                            }}</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        <TableRow
                            v-for="run in runs"
                            :key="run.id"
                            :class="current?.id === run.id ? 'bg-muted/50' : ''"
                            :data-test="`run-${run.status}`"
                        >
                            <TableCell class="min-w-0">
                                <Link
                                    :href="
                                        evaluations.show(set.id, {
                                            query: { run: run.id },
                                        })
                                    "
                                    preserve-scroll
                                    class="font-medium hover:underline"
                                    >{{
                                        run.label ?? formatDate(run.created_at)
                                    }}</Link
                                >
                                <p class="text-xs text-muted-foreground">
                                    {{ settingsSummary(run) }}
                                </p>
                            </TableCell>
                            <TableCell>
                                <Badge
                                    :variant="
                                        evaluationStatusVariant[run.status]
                                    "
                                    >{{
                                        t(evaluationStatusLabels[run.status])
                                    }}</Badge
                                >
                                <p
                                    v-if="run.error"
                                    class="mt-1 text-xs text-destructive"
                                >
                                    {{ run.error }}
                                </p>
                            </TableCell>
                            <TableCell class="text-right tabular-nums">{{
                                formatRatio(run.metrics?.recall_at_k)
                            }}</TableCell>
                            <TableCell class="text-right tabular-nums">{{
                                run.metrics ? run.metrics.mrr.toFixed(3) : '—'
                            }}</TableCell>
                            <TableCell class="text-right tabular-nums">{{
                                formatRatio(run.metrics?.hit_rate)
                            }}</TableCell>
                            <TableCell class="text-right tabular-nums">{{
                                formatRatio(run.metrics?.no_result_rate)
                            }}</TableCell>
                            <TableCell class="text-right tabular-nums">{{
                                run.metrics
                                    ? formatMs(run.metrics.p95_latency_ms)
                                    : '—'
                            }}</TableCell>
                            <TableCell class="text-right tabular-nums">{{
                                run.metrics ? formatCost(run.metrics.cost) : '—'
                            }}</TableCell>
                        </TableRow>
                        <TableEmpty v-if="!runs.length" :colspan="8">{{
                            t('No runs yet.')
                        }}</TableEmpty>
                    </TableBody>
                </Table>
            </CardContent>
        </Card>

        <div class="grid gap-6 xl:grid-cols-3">
            <div class="grid min-w-0 content-start gap-6">
                <Card class="min-w-0">
                    <CardHeader>
                        <CardTitle class="flex items-center gap-2"
                            ><Play class="size-4" />{{
                                t('New run')
                            }}</CardTitle
                        >
                        <CardDescription>{{
                            t(
                                'Every question is searched as you, with your access lists. Empty fields use the settings of each collection.',
                            )
                        }}</CardDescription>
                    </CardHeader>
                    <CardContent>
                        <form class="grid gap-4" @submit.prevent="startRun">
                            <div class="grid gap-2">
                                <Label for="run-label">{{ t('Label') }}</Label>
                                <Input
                                    id="run-label"
                                    v-model="runForm.label"
                                    :placeholder="t('e.g. hybrid k=8')"
                                />
                            </div>
                            <div class="grid gap-4 sm:grid-cols-2">
                                <div class="grid gap-2 sm:col-span-2">
                                    <Label>{{ t('Method') }}</Label>
                                    <Select v-model="runForm.method">
                                        <SelectTrigger class="w-full min-w-0"
                                            ><SelectValue
                                        /></SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="default">{{
                                                t('Collection settings')
                                            }}</SelectItem>
                                            <SelectItem
                                                v-for="(
                                                    label, value
                                                ) in methodLabels"
                                                :key="value"
                                                :value="value"
                                                >{{ t(label) }}</SelectItem
                                            >
                                        </SelectContent>
                                    </Select>
                                </div>
                                <div class="grid gap-2">
                                    <Label for="run-k">Top-k</Label>
                                    <Input
                                        id="run-k"
                                        v-model="runForm.top_k"
                                        type="number"
                                        min="1"
                                        max="50"
                                    />
                                </div>
                                <div class="grid gap-2">
                                    <Label for="run-min">{{
                                        t('Minimum similarity')
                                    }}</Label>
                                    <Input
                                        id="run-min"
                                        v-model="runForm.min_score"
                                        type="number"
                                        min="0"
                                        max="1"
                                        step="0.01"
                                    />
                                </div>
                                <div class="grid gap-2">
                                    <Label for="run-context">{{
                                        t('Context budget (tokens)')
                                    }}</Label>
                                    <Input
                                        id="run-context"
                                        v-model="runForm.max_context_tokens"
                                        type="number"
                                        min="100"
                                    />
                                </div>
                            </div>
                            <InputError
                                :message="
                                    (runForm.errors as Record<string, string>)
                                        .set ?? runForm.errors.method
                                "
                            />
                            <Button
                                type="submit"
                                :disabled="runForm.processing || !items.length"
                                data-test="run-evaluation"
                            >
                                <Play class="size-4" />
                                {{ t('Run evaluation') }}
                            </Button>
                        </form>
                    </CardContent>
                </Card>

                <Card class="min-w-0">
                    <CardHeader>
                        <CardTitle class="flex items-center gap-2"
                            ><Plus class="size-4" />{{
                                t('Add question')
                            }}</CardTitle
                        >
                        <CardDescription>{{
                            t(
                                'Choose the documents a good search must return. Only documents you can read are listed.',
                            )
                        }}</CardDescription>
                    </CardHeader>
                    <CardContent>
                        <form class="grid gap-4" @submit.prevent="addItem">
                            <div class="grid gap-2">
                                <Label for="item-question">{{
                                    t('Question')
                                }}</Label>
                                <Textarea
                                    id="item-question"
                                    v-model="itemForm.question"
                                    rows="2"
                                    required
                                    data-test="item-question"
                                />
                                <InputError
                                    :message="itemForm.errors.question"
                                />
                            </div>
                            <div class="grid gap-2">
                                <Label for="item-answer">{{
                                    t('Expected answer (optional)')
                                }}</Label>
                                <Textarea
                                    id="item-answer"
                                    v-model="itemForm.expected_answer"
                                    rows="2"
                                />
                            </div>
                            <div class="grid gap-2">
                                <Label for="item-filter">{{
                                    t('Documents that should be found')
                                }}</Label>
                                <Input
                                    id="item-filter"
                                    v-model="filter"
                                    :placeholder="t('Filter documents')"
                                />
                                <div
                                    class="grid max-h-56 gap-1 overflow-y-auto rounded-md border p-2"
                                >
                                    <Label
                                        v-for="document in filteredDocuments"
                                        :key="document.id"
                                        class="flex items-start gap-2 text-sm font-normal"
                                    >
                                        <Checkbox
                                            class="mt-0.5"
                                            :model-value="
                                                itemForm.expected_document_ids.includes(
                                                    document.id,
                                                )
                                            "
                                            :data-test="`item-document-${document.id}`"
                                            @update:model-value="
                                                (checked) =>
                                                    toggleDocument(
                                                        document.id,
                                                        checked,
                                                    )
                                            "
                                        />
                                        <span class="min-w-0">
                                            {{ document.title }}
                                            <span
                                                class="block text-xs text-muted-foreground"
                                                >{{ document.collection }}</span
                                            >
                                        </span>
                                    </Label>
                                    <p
                                        v-if="!filteredDocuments.length"
                                        class="p-2 text-xs text-muted-foreground"
                                    >
                                        {{ t('No indexed documents.') }}
                                    </p>
                                </div>
                                <InputError
                                    :message="
                                        itemForm.errors.expected_document_ids
                                    "
                                />
                            </div>
                            <Button
                                type="submit"
                                :disabled="
                                    itemForm.processing ||
                                    !itemForm.expected_document_ids.length
                                "
                                data-test="item-submit"
                            >
                                {{ t('Add question') }}
                            </Button>
                        </form>
                    </CardContent>
                </Card>
            </div>

            <Card class="min-w-0 xl:col-span-2">
                <CardHeader>
                    <CardTitle>{{
                        t('Questions (:n)', { n: items.length })
                    }}</CardTitle>
                    <CardDescription v-if="current">{{
                        t('Results of :run', {
                            run:
                                current.label ?? formatDate(current.created_at),
                        })
                    }}</CardDescription>
                </CardHeader>
                <CardContent class="grid gap-3">
                    <div
                        v-for="item in items"
                        :key="item.id"
                        class="grid gap-2 rounded-md border p-3 text-sm"
                        :data-test="`item-${item.id}`"
                    >
                        <div class="flex items-start justify-between gap-2">
                            <p class="font-medium">{{ item.question }}</p>
                            <Button
                                size="icon"
                                variant="ghost"
                                class="size-7 shrink-0"
                                :aria-label="t('Delete')"
                                @click="removeItem(item.id)"
                            >
                                <Trash2 class="size-3.5" />
                            </Button>
                        </div>
                        <p
                            v-if="item.expected_answer"
                            class="text-xs text-muted-foreground"
                        >
                            {{ item.expected_answer }}
                        </p>
                        <div class="flex flex-wrap items-center gap-1 text-xs">
                            <span class="text-muted-foreground">{{
                                t('Expected:')
                            }}</span>
                            <Badge
                                v-for="document in item.expected"
                                :key="document.id"
                                variant="outline"
                                >{{ document.title }}</Badge
                            >
                        </div>
                        <template v-if="resultFor(item)">
                            <p class="text-xs text-muted-foreground">
                                {{
                                    t(
                                        'Recall :recall · first relevant :rank · :ms',
                                        {
                                            recall: formatRatio(
                                                resultFor(item)!.recall,
                                            ),
                                            rank: resultFor(item)!
                                                .first_relevant_rank
                                                ? `#${resultFor(item)!.first_relevant_rank}`
                                                : t('not found'),
                                            ms: formatMs(
                                                resultFor(item)!.latency_ms,
                                            ),
                                        },
                                    )
                                }}
                            </p>
                            <ol class="grid gap-0.5 text-xs">
                                <li
                                    v-for="(document, index) in resultFor(item)!
                                        .retrieved"
                                    :key="document.id"
                                    class="flex items-center gap-1.5"
                                >
                                    <span
                                        class="w-5 text-right font-mono text-muted-foreground"
                                        >{{ index + 1 }}</span
                                    >
                                    <Check
                                        v-if="isExpected(item, document.id)"
                                        class="size-3.5 text-emerald-600 dark:text-emerald-400"
                                        :aria-label="t('Expected')"
                                    />
                                    <X
                                        v-else
                                        class="size-3.5 text-muted-foreground"
                                        :aria-label="t('Not expected')"
                                    />
                                    <span
                                        :class="
                                            isExpected(item, document.id)
                                                ? 'font-medium'
                                                : 'text-muted-foreground'
                                        "
                                        >{{ document.title }}</span
                                    >
                                </li>
                                <li
                                    v-if="!resultFor(item)!.retrieved.length"
                                    class="text-muted-foreground"
                                >
                                    {{ t('No passage retrieved.') }}
                                </li>
                            </ol>
                        </template>
                    </div>
                    <p
                        v-if="!items.length"
                        class="rounded-md border border-dashed p-6 text-center text-sm text-muted-foreground"
                    >
                        {{
                            t(
                                'Add the first question, or save one from the retrieval lab.',
                            )
                        }}
                    </p>
                    <p v-if="current" class="text-xs text-muted-foreground">
                        {{
                            t(':n questions evaluated', {
                                n: formatNumber(
                                    current.metrics?.questions ?? 0,
                                ),
                            })
                        }}
                    </p>
                </CardContent>
            </Card>
        </div>

        <EvaluationSetDialog
            v-model:open="editing"
            :collections="collections"
            :set="set"
        />
    </div>
</template>
