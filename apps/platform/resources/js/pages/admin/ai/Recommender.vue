<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { Sparkles, Trophy } from '@lucide/vue';
import { computed, ref } from 'vue';
import InputError from '@/components/InputError.vue';
import PageHeader from '@/components/PageHeader.vue';
import { Alert, AlertDescription } from '@starter/ui/components/alert';
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
    TableHead,
    TableHeader,
    TableRow,
} from '@starter/ui/components/table';
import { Textarea } from '@starter/ui/components/textarea';
import { formatContext, formatCost, formatPrice } from '@/lib/ai';
import { t } from '@/lib/i18n';
import { recommender } from '@/routes/admin/ai';
import { store } from '@/routes/admin/ai/recommender';
import type { LabModelOption, RecommendationResult } from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Model recommender', href: recommender() }],
    },
});

const props = defineProps<{
    models: LabModelOption[];
    defaultEvaluatorId: number | null;
    capabilities: string[];
    functions: string[];
}>();

const form = useForm({
    use_case: '',
    function: 'text',
    capabilities: [] as string[],
    max_input_price: '' as string | number,
    max_output_price: '' as string | number,
    min_context: '' as string | number,
    evaluator: props.defaultEvaluatorId
        ? String(props.defaultEvaluatorId)
        : 'none',
});

const result = ref<RecommendationResult | null>(null);

const capabilityLabels: Record<string, string> = {
    streaming: 'Streaming',
    tools: 'Tools',
    structured_output: 'Structured output',
    vision: 'Images',
    documents: 'Documents',
    audio_input: 'Audio input',
    reasoning: 'Reasoning',
    prompt_caching: 'Prompt caching',
    temperature: 'Temperature',
    web_search: 'Web search',
};

const toggle = (capability: string, checked: boolean | string) => {
    form.capabilities = checked
        ? [...new Set([...form.capabilities, capability])]
        : form.capabilities.filter((item) => item !== capability);
};

const submit = () =>
    form
        .transform((data) => ({
            use_case: data.use_case,
            function: data.function,
            capabilities: data.capabilities,
            max_input_price:
                data.max_input_price === '' ? null : data.max_input_price,
            max_output_price:
                data.max_output_price === '' ? null : data.max_output_price,
            min_context: data.min_context === '' ? null : data.min_context,
            evaluator_model_id:
                data.evaluator === 'none' ? null : Number(data.evaluator),
        }))
        .post(store().url, {
            preserveScroll: true,
            preserveState: true,
            onFlash: (flash) => {
                result.value =
                    ((flash as Record<string, unknown>).recommendation as
                        | RecommendationResult
                        | undefined) ?? null;
            },
        });

const supported = (candidate: RecommendationResult['candidates'][number]) =>
    Object.entries(candidate.capabilities)
        .filter(([, state]) => state.status === 'supported')
        .map(([name, state]) => ({ name, verified: state.verified }));

const sourceLabel = computed(() =>
    result.value?.source === 'evaluator'
        ? t('Compared by the evaluator model')
        : t(
              'Deterministic ranking (requirements, price, capabilities and context)',
          ),
);
</script>

<template>
    <Head :title="t('Model recommender')" />

    <div class="flex flex-col gap-6 p-4 md:p-6">
        <PageHeader
            :title="t('Model recommender')"
            :description="
                t(
                    'Describe the use case. The catalog is filtered by verifiable requirements and an evaluator model compares only those candidates. Prices and capabilities always come from the catalog.',
                )
            "
        />

        <div class="grid gap-6 xl:grid-cols-5">
            <Card class="min-w-0 xl:col-span-2">
                <CardHeader>
                    <CardTitle>{{ t('Requirements') }}</CardTitle>
                </CardHeader>
                <CardContent>
                    <form class="grid gap-4" @submit.prevent="submit">
                        <div class="grid gap-2">
                            <Label for="use_case">{{ t('Use case') }}</Label>
                            <Textarea
                                id="use_case"
                                v-model="form.use_case"
                                rows="5"
                                :placeholder="
                                    t(
                                        'E.g. a support assistant that answers in Spanish and manages users with tools',
                                    )
                                "
                            />
                            <InputError :message="form.errors.use_case" />
                        </div>
                        <div class="grid gap-2">
                            <Label>{{ t('Function') }}</Label>
                            <Select v-model="form.function">
                                <SelectTrigger><SelectValue /></SelectTrigger>
                                <SelectContent>
                                    <SelectItem
                                        v-for="fn in functions"
                                        :key="fn"
                                        :value="fn"
                                        >{{ t(fn) }}</SelectItem
                                    >
                                </SelectContent>
                            </Select>
                        </div>
                        <div class="grid gap-2">
                            <Label>{{ t('Required capabilities') }}</Label>
                            <div class="flex flex-wrap gap-x-4 gap-y-2">
                                <Label
                                    v-for="capability in capabilities"
                                    :key="capability"
                                    class="flex items-center gap-2 font-normal"
                                >
                                    <Checkbox
                                        :model-value="
                                            form.capabilities.includes(
                                                capability,
                                            )
                                        "
                                        @update:model-value="
                                            (checked) =>
                                                toggle(capability, checked)
                                        "
                                    />
                                    {{
                                        t(
                                            capabilityLabels[capability] ??
                                                capability,
                                        )
                                    }}
                                </Label>
                            </div>
                        </div>
                        <div class="grid gap-4 sm:grid-cols-2">
                            <div class="grid gap-2">
                                <Label for="max_input">{{
                                    t('Max input price (1M)')
                                }}</Label>
                                <Input
                                    id="max_input"
                                    v-model="form.max_input_price"
                                    type="number"
                                    min="0"
                                    step="any"
                                />
                            </div>
                            <div class="grid gap-2">
                                <Label for="max_output">{{
                                    t('Max output price (1M)')
                                }}</Label>
                                <Input
                                    id="max_output"
                                    v-model="form.max_output_price"
                                    type="number"
                                    min="0"
                                    step="any"
                                />
                            </div>
                        </div>
                        <div class="grid gap-2">
                            <Label for="min_context">{{
                                t('Minimum context window')
                            }}</Label>
                            <Input
                                id="min_context"
                                v-model="form.min_context"
                                type="number"
                                min="1"
                            />
                        </div>
                        <div class="grid gap-2">
                            <Label>{{ t('Evaluator model') }}</Label>
                            <Select v-model="form.evaluator">
                                <SelectTrigger><SelectValue /></SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="none">{{
                                        t('None (deterministic ranking only)')
                                    }}</SelectItem>
                                    <SelectItem
                                        v-for="model in models"
                                        :key="model.id"
                                        :value="String(model.id)"
                                        >{{ model.label }} ·
                                        {{ model.connection }}</SelectItem
                                    >
                                </SelectContent>
                            </Select>
                            <p class="text-xs text-muted-foreground">
                                {{
                                    t(
                                        'The evaluator call is recorded in the ledger as a recommendation.',
                                    )
                                }}
                            </p>
                        </div>
                        <Button type="submit" :disabled="form.processing">
                            <Sparkles class="size-4" />
                            {{
                                form.processing
                                    ? t('Analyzing…')
                                    : t('Recommend')
                            }}
                        </Button>
                    </form>
                </CardContent>
            </Card>

            <div class="grid min-w-0 content-start gap-6 xl:col-span-3">
                <Alert v-if="!result">
                    <AlertDescription>{{
                        t('The recommendation will appear here.')
                    }}</AlertDescription>
                </Alert>

                <template v-else>
                    <Card>
                        <CardHeader>
                            <CardTitle class="flex items-center gap-2">
                                <Trophy class="size-4" />
                                {{ t('Recommendation') }}
                            </CardTitle>
                            <CardDescription>
                                {{ sourceLabel }}
                                <template v-if="result.evaluator">
                                    · {{ result.evaluator.model }} ·
                                    {{ formatCost(result.evaluator.cost) }}
                                    <span
                                        v-if="!result.evaluator.valid"
                                        class="text-destructive"
                                    >
                                        ·
                                        {{
                                            t(
                                                'the evaluator answer was not valid',
                                            )
                                        }}</span
                                    >
                                </template>
                            </CardDescription>
                        </CardHeader>
                        <CardContent class="grid gap-4">
                            <p v-if="result.summary" class="text-sm">
                                {{ result.summary }}
                            </p>
                            <p
                                v-if="!result.recommendations.length"
                                class="text-sm text-muted-foreground"
                            >
                                {{ t('No model meets these requirements.') }}
                            </p>
                            <div
                                v-for="item in result.recommendations"
                                :key="item.id"
                                class="grid gap-2 rounded-lg border p-4"
                            >
                                <div class="flex flex-wrap items-center gap-2">
                                    <Badge>#{{ item.rank }}</Badge>
                                    <span class="font-medium">{{
                                        item.label
                                    }}</span>
                                    <span
                                        class="text-xs text-muted-foreground"
                                        >{{ item.connection }}</span
                                    >
                                    <span
                                        class="ml-auto text-xs text-muted-foreground"
                                        >{{ t('Score') }}
                                        {{ item.score }}/100</span
                                    >
                                </div>
                                <p v-if="item.explanation" class="text-sm">
                                    {{ item.explanation }}
                                </p>
                                <ul
                                    class="list-disc pl-5 text-xs text-muted-foreground"
                                >
                                    <li
                                        v-for="reason in item.reasons"
                                        :key="reason"
                                    >
                                        {{ reason }}
                                    </li>
                                </ul>
                            </div>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle>{{ t('Candidates') }}</CardTitle>
                            <CardDescription>{{
                                t(
                                    ':count model(s) do not meet the requirements.',
                                ).replace(':count', String(result.excluded))
                            }}</CardDescription>
                        </CardHeader>
                        <CardContent class="overflow-x-auto">
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>{{ t('Model') }}</TableHead>
                                        <TableHead>{{
                                            t('Input / output (1M)')
                                        }}</TableHead>
                                        <TableHead>{{
                                            t('Context')
                                        }}</TableHead>
                                        <TableHead>{{
                                            t('Capabilities')
                                        }}</TableHead>
                                        <TableHead class="text-right">{{
                                            t('Score')
                                        }}</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    <TableRow
                                        v-for="candidate in result.candidates"
                                        :key="candidate.id"
                                    >
                                        <TableCell>
                                            <div class="font-medium">
                                                {{ candidate.label }}
                                            </div>
                                            <div
                                                class="text-xs text-muted-foreground"
                                            >
                                                {{ candidate.connection }}
                                            </div>
                                        </TableCell>
                                        <TableCell
                                            class="text-sm whitespace-nowrap"
                                        >
                                            {{
                                                candidate.input_price !== null
                                                    ? formatPrice(
                                                          candidate.input_price,
                                                      )
                                                    : t('Unknown')
                                            }}
                                            /
                                            {{
                                                candidate.output_price !== null
                                                    ? formatPrice(
                                                          candidate.output_price,
                                                      )
                                                    : t('Unknown')
                                            }}
                                        </TableCell>
                                        <TableCell>{{
                                            formatContext(
                                                candidate.context_window,
                                            )
                                        }}</TableCell>
                                        <TableCell>
                                            <div class="flex flex-wrap gap-1">
                                                <Badge
                                                    v-for="capability in supported(
                                                        candidate,
                                                    )"
                                                    :key="capability.name"
                                                    :variant="
                                                        capability.verified
                                                            ? 'default'
                                                            : 'outline'
                                                    "
                                                    >{{
                                                        t(
                                                            capabilityLabels[
                                                                capability.name
                                                            ] ??
                                                                capability.name,
                                                        )
                                                    }}</Badge
                                                >
                                            </div>
                                        </TableCell>
                                        <TableCell class="text-right">{{
                                            candidate.score
                                        }}</TableCell>
                                    </TableRow>
                                </TableBody>
                            </Table>
                        </CardContent>
                    </Card>
                </template>
            </div>
        </div>
    </div>
</template>
