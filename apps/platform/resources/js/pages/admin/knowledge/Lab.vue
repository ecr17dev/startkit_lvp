<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import {
    FlaskConical,
    ListPlus,
    Search,
    ThumbsDown,
    ThumbsUp,
} from '@lucide/vue';
import { computed, ref } from 'vue';
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
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@starter/ui/components/select';
import { Textarea } from '@starter/ui/components/textarea';
import { usePermissions } from '@/composables/usePermissions';
import { formatCost } from '@/lib/ai';
import { formatDate, formatNumber, t } from '@/lib/i18n';
import { formatMs, methodLabels } from '@/lib/knowledge';
import knowledge from '@/routes/admin/knowledge';
import { show as documentShow } from '@/routes/admin/knowledge/documents';
import evaluations from '@/routes/admin/knowledge/evaluations';
import lab from '@/routes/admin/knowledge/lab';
import type {
    LabCollectionOption,
    RetrievalHitRow,
    RetrievalResultData,
    RetrievalRunSummary,
} from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Knowledge', href: knowledge.index() },
            { title: 'Retrieval lab', href: knowledge.lab() },
        ],
    },
});

defineProps<{
    available: boolean;
    collections: LabCollectionOption[];
    recent: RetrievalRunSummary[];
}>();

const { can } = usePermissions();

const form = useForm({
    query: '',
    collection_ids: [] as number[],
    method: 'hybrid',
    compare: false,
    top_k: 6 as number | string,
    min_score: '' as number | string,
    max_context_tokens: 2000 as number | string,
});

const results = ref<RetrievalResultData[]>([]);
const searched = ref('');
const expanded = ref<Record<string, boolean>>({});
const sets = ref<{ id: number; name: string }[]>([]);
// Relevance judgements of the stored hits (1 relevant, -1 not relevant)...
const judgements = ref<Record<number, number>>({});

const toggleCollection = (id: number, checked: boolean | string) => {
    form.collection_ids = checked
        ? [...new Set([...form.collection_ids, id])]
        : form.collection_ids.filter((item) => item !== id);
};

const submit = () =>
    form
        .transform((data) => ({
            query: data.query,
            collection_ids: data.collection_ids.length
                ? data.collection_ids
                : null,
            method: data.compare ? null : data.method,
            compare: data.compare ? ['vector', 'text', 'hybrid'] : null,
            top_k: Number(data.top_k),
            min_score: data.min_score === '' ? null : Number(data.min_score),
            max_context_tokens: Number(data.max_context_tokens),
        }))
        .post(lab.search().url, {
            preserveScroll: true,
            preserveState: true,
            onFlash: (flash) => {
                const search = (flash as Record<string, unknown>).search as
                    | { query: string; results: RetrievalResultData[] }
                    | undefined;
                results.value = search?.results ?? [];
                searched.value = search?.query ?? '';
                sets.value =
                    (
                        (flash as Record<string, unknown>).search as
                            | { sets?: { id: number; name: string }[] }
                            | undefined
                    )?.sets ?? [];
                expanded.value = {};
                judgements.value = {};
            },
        });

const judge = (hitId: number, value: 1 | -1) => {
    const next = judgements.value[hitId] === value ? 0 : value;
    const previous = judgements.value[hitId];
    judgements.value = { ...judgements.value, [hitId]: next };

    router.post(
        lab.judge(hitId).url,
        { judgement: next },
        {
            preserveScroll: true,
            preserveState: true,
            onError: () =>
                (judgements.value = { ...judgements.value, [hitId]: previous }),
        },
    );
};

// Documents found by the search: candidates for the expected answer...
const foundDocuments = computed(() => {
    const documents = new Map<number, string>();

    for (const result of results.value) {
        for (const hit of result.hits) {
            if (hit.document_id) {
                documents.set(hit.document_id, hit.title);
            }
        }
    }

    return [...documents].map(([id, title]) => ({ id, title }));
});

const saveOpen = ref(false);
const saveForm = useForm({
    set: '' as string,
    question: '',
    expected_document_ids: [] as number[],
});

const openSave = () => {
    const relevant = new Set<number>();

    for (const result of results.value) {
        for (const hit of result.hits) {
            if (
                hit.hit_id &&
                hit.document_id &&
                judgements.value[hit.hit_id] === 1
            ) {
                relevant.add(hit.document_id);
            }
        }
    }

    saveForm.clearErrors();
    saveForm.set = sets.value[0] ? String(sets.value[0].id) : '';
    saveForm.question = searched.value;
    saveForm.expected_document_ids = [...relevant];
    saveOpen.value = true;
};

const toggleExpected = (id: number, checked: boolean | string) => {
    saveForm.expected_document_ids = checked
        ? [...new Set([...saveForm.expected_document_ids, id])]
        : saveForm.expected_document_ids.filter((item) => item !== id);
};

const saveQuestion = () =>
    saveForm
        .transform((data) => ({
            question: data.question,
            expected_document_ids: data.expected_document_ids,
        }))
        .post(evaluations.items.store(Number(saveForm.set)).url, {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => (saveOpen.value = false),
        });

// Passages of a retrieval API have an external id instead of a chunk...
const hitKey = (hit: RetrievalHitRow) =>
    hit.external_id ?? String(hit.chunk_id);

const scoreLabel: Record<string, string> = {
    rrf: 'RRF',
    cosine_similarity: 'cos',
    text_rank: 'ts_rank',
    inverse_l2: '1/(1+L2)',
    inner_product: 'dot',
};
</script>

<template>
    <Head :title="t('Retrieval lab')" />

    <div class="flex flex-col gap-6 p-4 md:p-6">
        <PageHeader
            :title="t('Retrieval lab')"
            :description="
                t(
                    'Write a query and inspect the passages your access lists allow: ranking, scores, method, latency and the cost of embedding the query. Scores rank within a method; they are not probabilities.',
                )
            "
        >
            <template #icon><FlaskConical class="size-5" /></template>
        </PageHeader>

        <p
            v-if="!available"
            class="rounded-md border border-dashed p-3 text-sm text-muted-foreground"
        >
            {{
                t(
                    'Knowledge (RAG) is not active. An administrator must activate it after the diagnostics.',
                )
            }}
        </p>

        <div class="grid gap-6 xl:grid-cols-4">
            <Card class="min-w-0">
                <CardHeader>
                    <CardTitle class="flex items-center gap-2"
                        ><Search class="size-4" />{{ t('Query') }}</CardTitle
                    >
                    <CardDescription>{{
                        t(
                            'Every search is traced (query, hits, latency and cost).',
                        )
                    }}</CardDescription>
                </CardHeader>
                <CardContent>
                    <form class="grid gap-4" @submit.prevent="submit">
                        <div class="grid gap-2">
                            <Label for="lab-query">{{ t('Question') }}</Label>
                            <Textarea
                                id="lab-query"
                                v-model="form.query"
                                rows="3"
                                required
                                data-test="lab-query"
                            />
                            <InputError :message="form.errors.query" />
                        </div>
                        <div class="grid gap-2">
                            <Label>{{ t('Collections') }}</Label>
                            <Label
                                v-for="collection in collections"
                                :key="collection.id"
                                class="flex items-center gap-2 text-sm font-normal"
                            >
                                <Checkbox
                                    :model-value="
                                        form.collection_ids.includes(
                                            collection.id,
                                        )
                                    "
                                    @update:model-value="
                                        (checked) =>
                                            toggleCollection(
                                                collection.id,
                                                checked,
                                            )
                                    "
                                />
                                {{ collection.name }}
                            </Label>
                            <p class="text-xs text-muted-foreground">
                                {{
                                    t(
                                        'None selected: every collection you can access.',
                                    )
                                }}
                            </p>
                        </div>
                        <div class="grid gap-4 sm:grid-cols-2">
                            <div class="grid gap-2 sm:col-span-2">
                                <Label>{{ t('Method') }}</Label>
                                <Select
                                    v-model="form.method"
                                    :disabled="form.compare"
                                >
                                    <SelectTrigger class="w-full min-w-0"
                                        ><SelectValue
                                    /></SelectTrigger>
                                    <SelectContent>
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
                                <Label for="lab-k">Top-k</Label>
                                <Input
                                    id="lab-k"
                                    v-model="form.top_k"
                                    type="number"
                                    min="1"
                                    max="50"
                                />
                            </div>
                            <div class="grid gap-2">
                                <Label for="lab-min">{{
                                    t('Minimum similarity')
                                }}</Label>
                                <Input
                                    id="lab-min"
                                    v-model="form.min_score"
                                    type="number"
                                    min="0"
                                    max="1"
                                    step="0.01"
                                    :placeholder="t('none')"
                                />
                            </div>
                            <div class="grid gap-2">
                                <Label for="lab-context">{{
                                    t('Context budget (tokens)')
                                }}</Label>
                                <Input
                                    id="lab-context"
                                    v-model="form.max_context_tokens"
                                    type="number"
                                    min="100"
                                />
                            </div>
                        </div>
                        <Label
                            class="flex items-center gap-2 text-sm font-normal"
                        >
                            <Checkbox
                                v-model="form.compare"
                                data-test="lab-compare"
                            />
                            {{
                                t(
                                    'Compare vector, full text and hybrid side by side',
                                )
                            }}
                        </Label>
                        <Button
                            type="submit"
                            :disabled="form.processing || !available"
                            data-test="lab-submit"
                        >
                            <Search class="size-4" />
                            {{ t('Search') }}
                        </Button>
                    </form>
                </CardContent>
            </Card>

            <div class="grid min-w-0 gap-4 xl:col-span-3">
                <p
                    v-if="!results.length"
                    class="rounded-md border border-dashed p-6 text-center text-sm text-muted-foreground"
                >
                    {{ t('The passages will appear here.') }}
                </p>
                <div
                    v-if="results.length && can('rag.playground.use')"
                    class="flex flex-wrap items-center justify-between gap-2"
                >
                    <p class="text-xs text-muted-foreground">
                        {{
                            t(
                                'Mark each passage as relevant or not: the judgements are kept with the trace and help to build evaluation sets.',
                            )
                        }}
                    </p>
                    <Button
                        variant="outline"
                        size="sm"
                        data-test="lab-save-question"
                        @click="openSave"
                    >
                        <ListPlus class="size-4" />
                        {{ t('Save as evaluation question') }}
                    </Button>
                </div>
                <div
                    v-if="results.length"
                    class="grid gap-4"
                    :class="results.length > 1 ? 'lg:grid-cols-3' : ''"
                >
                    <Card
                        v-for="result in results"
                        :key="result.run_id ?? result.method"
                        class="min-w-0"
                        :data-test="`lab-result-${result.method}`"
                    >
                        <CardHeader class="gap-1">
                            <CardTitle
                                class="flex flex-wrap items-center gap-2 text-base"
                            >
                                {{
                                    t(
                                        methodLabels[result.method] ??
                                            result.method,
                                    )
                                }}
                                <Badge variant="secondary">{{
                                    t(':n passages', { n: result.hits.length })
                                }}</Badge>
                            </CardTitle>
                            <CardDescription class="text-xs">
                                {{ formatMs(result.latency_ms) }}
                                <template v-if="result.embedding_ms !== null">
                                    ·
                                    {{
                                        t('embedding :ms', {
                                            ms: formatMs(result.embedding_ms),
                                        })
                                    }}</template
                                >
                                ·
                                {{
                                    t('search :ms', {
                                        ms: formatMs(result.search_ms),
                                    })
                                }}
                                · {{ formatCost(result.cost) }} ·
                                {{ formatNumber(result.context_tokens) }} tokens
                                <template
                                    v-if="
                                        Object.values(
                                            result.diagnostics.exact ?? {},
                                        ).some(Boolean)
                                    "
                                >
                                    · {{ t('exact search') }}</template
                                >
                            </CardDescription>
                        </CardHeader>
                        <CardContent class="grid gap-3">
                            <div
                                v-for="hit in result.hits"
                                :key="hitKey(hit)"
                                class="grid gap-1 rounded-md border p-3 text-sm"
                            >
                                <div class="flex flex-wrap items-center gap-2">
                                    <Badge variant="outline"
                                        >#{{ hit.rank }}</Badge
                                    >
                                    <Link
                                        v-if="
                                            can('rag.documents.read') &&
                                            hit.document_id
                                        "
                                        :href="documentShow(hit.document_id)"
                                        class="font-medium hover:underline"
                                        >{{ hit.title }}</Link
                                    >
                                    <span v-else class="font-medium">{{
                                        hit.title
                                    }}</span>
                                    <span
                                        v-if="hit.hit_id"
                                        class="ml-auto flex items-center gap-0.5"
                                    >
                                        <button
                                            type="button"
                                            class="rounded p-1 text-muted-foreground hover:bg-muted hover:text-foreground"
                                            :class="
                                                judgements[hit.hit_id] === 1 &&
                                                'text-emerald-600 dark:text-emerald-400'
                                            "
                                            :aria-pressed="
                                                judgements[hit.hit_id] === 1
                                            "
                                            :aria-label="t('Relevant')"
                                            :data-test="`judge-up-${hit.rank}`"
                                            @click="judge(hit.hit_id, 1)"
                                        >
                                            <ThumbsUp class="size-3.5" />
                                        </button>
                                        <button
                                            type="button"
                                            class="rounded p-1 text-muted-foreground hover:bg-muted hover:text-foreground"
                                            :class="
                                                judgements[hit.hit_id] === -1 &&
                                                'text-destructive'
                                            "
                                            :aria-pressed="
                                                judgements[hit.hit_id] === -1
                                            "
                                            :aria-label="t('Not relevant')"
                                            :data-test="`judge-down-${hit.rank}`"
                                            @click="judge(hit.hit_id, -1)"
                                        >
                                            <ThumbsDown class="size-3.5" />
                                        </button>
                                    </span>
                                </div>
                                <div class="text-xs text-muted-foreground">
                                    <span v-if="hit.location"
                                        >{{ hit.location }} ·
                                    </span>
                                    {{
                                        scoreLabel[hit.score_type] ??
                                        hit.score_type
                                    }}
                                    {{ hit.score.toFixed(4) }}
                                    <template
                                        v-if="
                                            hit.vector_score !== undefined &&
                                            hit.score_type === 'rrf'
                                        "
                                    >
                                        · cos
                                        {{
                                            hit.vector_score.toFixed(3)
                                        }}</template
                                    >
                                    <template
                                        v-if="
                                            hit.text_score !== undefined &&
                                            hit.score_type === 'rrf'
                                        "
                                    >
                                        · ts
                                        {{
                                            hit.text_score.toFixed(3)
                                        }}</template
                                    >
                                    · {{ hit.token_count }} tokens
                                </div>
                                <p
                                    class="text-sm whitespace-pre-wrap text-muted-foreground"
                                    :class="
                                        expanded[
                                            `${result.method}-${hitKey(hit)}`
                                        ]
                                            ? ''
                                            : 'line-clamp-4'
                                    "
                                >
                                    {{ hit.content }}
                                </p>
                                <button
                                    type="button"
                                    class="w-fit text-xs text-muted-foreground hover:text-foreground"
                                    @click="
                                        expanded[
                                            `${result.method}-${hitKey(hit)}`
                                        ] =
                                            !expanded[
                                                `${result.method}-${hitKey(hit)}`
                                            ]
                                    "
                                >
                                    {{
                                        expanded[
                                            `${result.method}-${hitKey(hit)}`
                                        ]
                                            ? t('Show less')
                                            : t('Show more')
                                    }}
                                </button>
                            </div>
                            <p
                                v-if="!result.hits.length"
                                class="text-sm text-muted-foreground"
                            >
                                {{ t('No authorized passage matched.') }}
                            </p>
                        </CardContent>
                    </Card>
                </div>

                <Card v-if="recent.length">
                    <CardHeader
                        ><CardTitle class="text-base">{{
                            t('Your recent searches')
                        }}</CardTitle></CardHeader
                    >
                    <CardContent class="grid gap-1 text-sm">
                        <div
                            v-for="run in recent"
                            :key="run.id"
                            class="flex flex-wrap justify-between gap-2 border-b py-1.5 last:border-0"
                        >
                            <span class="min-w-0 truncate">{{
                                run.query
                            }}</span>
                            <span class="text-xs text-muted-foreground">
                                {{ t(methodLabels[run.method] ?? run.method) }}
                                · {{ run.hits }} ·
                                {{ formatMs(run.latency_ms) }} ·
                                {{ formatCost(run.cost) }} ·
                                {{ formatDate(run.created_at) }}
                            </span>
                        </div>
                    </CardContent>
                </Card>
            </div>
        </div>

        <Dialog v-model:open="saveOpen">
            <DialogContent class="sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>{{
                        t('Save as evaluation question')
                    }}</DialogTitle>
                    <DialogDescription>{{
                        t(
                            'The question and the documents that should be found are added to an evaluation set.',
                        )
                    }}</DialogDescription>
                </DialogHeader>
                <p
                    v-if="!sets.length"
                    class="rounded-md border border-dashed p-3 text-sm text-muted-foreground"
                >
                    {{ t('Create an evaluation set first.') }}
                    <Link
                        :href="evaluations.index()"
                        class="font-medium text-foreground hover:underline"
                        >{{ t('Evaluations') }}</Link
                    >
                </p>
                <form
                    v-else
                    id="save-question"
                    class="grid gap-4"
                    @submit.prevent="saveQuestion"
                >
                    <div class="grid gap-2">
                        <Label>{{ t('Evaluation set') }}</Label>
                        <Select v-model="saveForm.set">
                            <SelectTrigger><SelectValue /></SelectTrigger>
                            <SelectContent>
                                <SelectItem
                                    v-for="set in sets"
                                    :key="set.id"
                                    :value="String(set.id)"
                                    >{{ set.name }}</SelectItem
                                >
                            </SelectContent>
                        </Select>
                    </div>
                    <div class="grid gap-2">
                        <Label for="save-question-text">{{
                            t('Question')
                        }}</Label>
                        <Textarea
                            id="save-question-text"
                            v-model="saveForm.question"
                            rows="2"
                            required
                        />
                        <InputError :message="saveForm.errors.question" />
                    </div>
                    <div class="grid gap-2">
                        <Label>{{ t('Documents that should be found') }}</Label>
                        <Label
                            v-for="document in foundDocuments"
                            :key="document.id"
                            class="flex items-center gap-2 text-sm font-normal"
                        >
                            <Checkbox
                                :model-value="
                                    saveForm.expected_document_ids.includes(
                                        document.id,
                                    )
                                "
                                @update:model-value="
                                    (checked) =>
                                        toggleExpected(document.id, checked)
                                "
                            />
                            {{ document.title }}
                        </Label>
                        <InputError
                            :message="saveForm.errors.expected_document_ids"
                        />
                    </div>
                </form>
                <DialogFooter>
                    <Button variant="outline" @click="saveOpen = false">{{
                        t('Cancel')
                    }}</Button>
                    <Button
                        v-if="sets.length"
                        type="submit"
                        form="save-question"
                        :disabled="
                            saveForm.processing ||
                            !saveForm.set ||
                            !saveForm.expected_document_ids.length
                        "
                        data-test="lab-save-question-submit"
                        >{{ t('Save') }}</Button
                    >
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </div>
</template>
