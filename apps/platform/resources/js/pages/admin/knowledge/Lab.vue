<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { FlaskConical, Search } from '@lucide/vue';
import { ref } from 'vue';
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
import lab from '@/routes/admin/knowledge/lab';
import type {
    LabCollectionOption,
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
                expanded.value = {};
            },
        });

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
                            <div class="grid gap-2">
                                <Label>{{ t('Method') }}</Label>
                                <Select
                                    v-model="form.method"
                                    :disabled="form.compare"
                                >
                                    <SelectTrigger
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
                    v-else
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
                                :key="hit.chunk_id"
                                class="grid gap-1 rounded-md border p-3 text-sm"
                            >
                                <div class="flex flex-wrap items-center gap-2">
                                    <Badge variant="outline"
                                        >#{{ hit.rank }}</Badge
                                    >
                                    <Link
                                        v-if="can('rag.documents.read')"
                                        :href="documentShow(hit.document_id)"
                                        class="font-medium hover:underline"
                                        >{{ hit.title }}</Link
                                    >
                                    <span v-else class="font-medium">{{
                                        hit.title
                                    }}</span>
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
                                            `${result.method}-${hit.chunk_id}`
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
                                            `${result.method}-${hit.chunk_id}`
                                        ] =
                                            !expanded[
                                                `${result.method}-${hit.chunk_id}`
                                            ]
                                    "
                                >
                                    {{
                                        expanded[
                                            `${result.method}-${hit.chunk_id}`
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
    </div>
</template>
