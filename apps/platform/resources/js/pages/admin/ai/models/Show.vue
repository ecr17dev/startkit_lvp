<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { CircleCheck } from '@lucide/vue';
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
import { Switch } from '@starter/ui/components/switch';
import {
    Table,
    TableBody,
    TableCell,
    TableEmpty,
    TableHead,
    TableHeader,
    TableRow,
} from '@starter/ui/components/table';
import {
    costStatusLabels,
    formatCost,
    formatPrice,
    modelStatusVariant,
    priceComponentLabels,
    priceUnitLabels,
} from '@/lib/ai';
import { formatDate, formatNumber, t } from '@/lib/i18n';
import models from '@/routes/admin/ai/models';
import type { AiCatalogModel, LedgerRequest, ModelPriceRow } from '@/types';

const props = defineProps<{
    model: AiCatalogModel;
    priceHistory: ModelPriceRow[];
    recentRequests: LedgerRequest[];
    functions: string[];
    capabilities: { value: string; label: string }[];
    components: { value: string; unit: string }[];
    units: string[];
}>();

defineOptions({
    layout: (props: { model: AiCatalogModel }) => ({
        breadcrumbs: [
            { title: 'Model catalog', href: models.index() },
            { title: props.model.name, href: models.show(props.model.id) },
        ],
    }),
});

const form = useForm({
    label: props.model.label ?? '',
    functions: props.model.functions ?? [],
    context_window: (props.model.context_window ?? '') as number | string,
    max_output_tokens: (props.model.max_output_tokens ?? '') as number | string,
    source_url: props.model.source_url ?? '',
    is_enabled: props.model.is_enabled,
    is_default: props.model.is_default,
    capabilities: Object.fromEntries(
        Object.entries(props.model.capabilities).map(([key, value]) => [
            key,
            value.status,
        ]),
    ) as Record<string, string>,
});

const priceForm = useForm({
    component: 'input',
    unit: 'per_million_tokens',
    amount: '',
    tier: 'standard',
    notes: '',
});

const toggleFunction = (fn: string, checked: boolean | 'indeterminate') => {
    form.functions = checked
        ? [...new Set([...form.functions, fn])]
        : form.functions.filter((item) => item !== fn);
};

const save = () =>
    form
        .transform((data) => ({
            ...data,
            context_window: data.context_window || null,
            max_output_tokens: data.max_output_tokens || null,
            source_url: data.source_url || null,
        }))
        .put(models.update(props.model.id).url, { preserveScroll: true });

const savePrice = () =>
    priceForm.post(models.prices.store(props.model.id).url, {
        preserveScroll: true,
        onSuccess: () => priceForm.reset('amount', 'notes'),
    });

const verify = () =>
    router.post(
        models.verify(props.model.id).url,
        {},
        { preserveScroll: true },
    );

const onComponentChange = (value: unknown) => {
    priceForm.component = String(value);
    priceForm.unit =
        props.components.find((item) => item.value === value)?.unit ??
        priceForm.unit;
};
</script>

<template>
    <Head :title="model.name" />

    <div class="flex max-w-6xl flex-col gap-6 p-4 md:p-6">
        <PageHeader
            :title="model.name"
            :description="`${model.model} · ${model.connection?.label ?? ''}`"
        >
            <div class="flex flex-wrap items-center gap-2">
                <Badge
                    :variant="modelStatusVariant[model.status] ?? 'outline'"
                    >{{ t(model.status) }}</Badge
                >
                <Button variant="outline" @click="verify">
                    <CircleCheck class="size-4" />
                    {{ t('Verify with a minimal call') }}
                </Button>
            </div>
        </PageHeader>

        <p class="text-xs text-muted-foreground">
            {{ t('Source') }}: {{ model.source }}
            <template v-if="model.discovered_at">
                · {{ t('Discovered') }}
                {{ formatDate(model.discovered_at) }}</template
            >
            <template v-if="model.verified_at">
                · {{ t('Verified') }}
                {{ formatDate(model.verified_at) }}</template
            >
            <template v-if="model.last_error">
                ·
                <span class="text-destructive">{{
                    model.last_error
                }}</span></template
            >
        </p>

        <div class="grid gap-6 lg:grid-cols-2">
            <Card>
                <CardHeader>
                    <CardTitle>{{ t('Model card') }}</CardTitle>
                    <CardDescription>{{
                        t('Unverified capabilities stay unknown.')
                    }}</CardDescription>
                </CardHeader>
                <CardContent>
                    <form class="grid gap-4" @submit.prevent="save">
                        <div class="grid gap-2">
                            <Label for="label">{{ t('Label') }}</Label>
                            <Input id="label" v-model="form.label" />
                        </div>
                        <div class="grid gap-2">
                            <Label>{{ t('Functions') }}</Label>
                            <div class="flex flex-wrap gap-3">
                                <Label
                                    v-for="fn in functions"
                                    :key="fn"
                                    class="flex items-center gap-2 font-normal"
                                >
                                    <Checkbox
                                        :model-value="
                                            form.functions.includes(fn)
                                        "
                                        @update:model-value="
                                            (value) => toggleFunction(fn, value)
                                        "
                                    />
                                    {{ t(fn) }}
                                </Label>
                            </div>
                        </div>
                        <div class="grid gap-4 sm:grid-cols-2">
                            <div class="grid gap-2">
                                <Label for="context_window">{{
                                    t('Context window')
                                }}</Label>
                                <Input
                                    id="context_window"
                                    v-model="form.context_window"
                                    type="number"
                                    min="1"
                                />
                                <InputError
                                    :message="form.errors.context_window"
                                />
                            </div>
                            <div class="grid gap-2">
                                <Label for="max_output_tokens">{{
                                    t('Max output tokens')
                                }}</Label>
                                <Input
                                    id="max_output_tokens"
                                    v-model="form.max_output_tokens"
                                    type="number"
                                    min="1"
                                />
                                <InputError
                                    :message="form.errors.max_output_tokens"
                                />
                            </div>
                        </div>
                        <div class="grid gap-2">
                            <Label for="source_url">{{
                                t('Source URL')
                            }}</Label>
                            <Input
                                id="source_url"
                                v-model="form.source_url"
                                type="url"
                            />
                            <InputError :message="form.errors.source_url" />
                        </div>

                        <div class="grid gap-2">
                            <Label>{{ t('Capabilities') }}</Label>
                            <div class="divide-y rounded-md border">
                                <div
                                    v-for="capability in capabilities"
                                    :key="capability.value"
                                    class="flex items-center justify-between gap-3 px-3 py-2"
                                >
                                    <div>
                                        <div class="text-sm">
                                            {{ t(capability.label) }}
                                        </div>
                                        <div
                                            class="text-xs text-muted-foreground"
                                        >
                                            {{
                                                model.capabilities[
                                                    capability.value
                                                ]?.source ?? t('No source')
                                            }}
                                        </div>
                                    </div>
                                    <Select
                                        v-model="
                                            form.capabilities[capability.value]
                                        "
                                    >
                                        <SelectTrigger class="w-36"
                                            ><SelectValue
                                        /></SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="unknown">{{
                                                t('Unknown')
                                            }}</SelectItem>
                                            <SelectItem value="supported">{{
                                                t('Supported')
                                            }}</SelectItem>
                                            <SelectItem value="unsupported">{{
                                                t('Not supported')
                                            }}</SelectItem>
                                        </SelectContent>
                                    </Select>
                                </div>
                            </div>
                            <InputError :message="form.errors.capabilities" />
                        </div>

                        <div class="flex items-center justify-between gap-4">
                            <Label for="is_enabled">{{
                                t('Enabled for the application')
                            }}</Label>
                            <Switch id="is_enabled" v-model="form.is_enabled" />
                        </div>
                        <div class="flex items-center justify-between gap-4">
                            <Label for="is_default">{{
                                t('Default chat model')
                            }}</Label>
                            <Switch id="is_default" v-model="form.is_default" />
                        </div>

                        <Button
                            type="submit"
                            :disabled="form.processing"
                            class="justify-self-start"
                            >{{ t('Save') }}</Button
                        >
                    </form>
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle>{{ t('Prices') }}</CardTitle>
                    <CardDescription>{{
                        t(
                            'USD. A change creates a new version: recorded costs keep the price they were calculated with. Missing prices make costs unknown, never free.',
                        )
                    }}</CardDescription>
                </CardHeader>
                <CardContent class="grid gap-4">
                    <dl class="grid grid-cols-2 gap-2 text-sm">
                        <template
                            v-for="(price, component) in model.prices ?? {}"
                            :key="component"
                        >
                            <dt class="text-muted-foreground">
                                {{
                                    t(
                                        priceComponentLabels[component] ??
                                            component,
                                    )
                                }}
                            </dt>
                            <dd>
                                {{ formatPrice(price.amount) }}
                                {{
                                    t(priceUnitLabels[price.unit] ?? price.unit)
                                }}
                                <span class="text-xs text-muted-foreground"
                                    >({{ price.source }})</span
                                >
                            </dd>
                        </template>
                        <p
                            v-if="!Object.keys(model.prices ?? {}).length"
                            class="col-span-2 text-muted-foreground"
                        >
                            {{
                                t(
                                    'No prices: the cost of this model is unknown.',
                                )
                            }}
                        </p>
                    </dl>

                    <form
                        class="grid gap-3 rounded-md border p-3"
                        @submit.prevent="savePrice"
                    >
                        <div class="grid gap-3 sm:grid-cols-3">
                            <Select
                                :model-value="priceForm.component"
                                @update:model-value="onComponentChange"
                            >
                                <SelectTrigger class="w-full"
                                    ><SelectValue
                                /></SelectTrigger>
                                <SelectContent>
                                    <SelectItem
                                        v-for="component in components"
                                        :key="component.value"
                                        :value="component.value"
                                        >{{
                                            t(
                                                priceComponentLabels[
                                                    component.value
                                                ] ?? component.value,
                                            )
                                        }}</SelectItem
                                    >
                                </SelectContent>
                            </Select>
                            <Input
                                v-model="priceForm.amount"
                                type="number"
                                step="any"
                                min="0"
                                :placeholder="t('Amount (empty stops it)')"
                                data-test="price-amount"
                            />
                            <Select v-model="priceForm.unit">
                                <SelectTrigger class="w-full"
                                    ><SelectValue
                                /></SelectTrigger>
                                <SelectContent>
                                    <SelectItem
                                        v-for="unit in units"
                                        :key="unit"
                                        :value="unit"
                                        >{{
                                            t(priceUnitLabels[unit] ?? unit)
                                        }}</SelectItem
                                    >
                                </SelectContent>
                            </Select>
                        </div>
                        <Input
                            v-model="priceForm.notes"
                            :placeholder="
                                t('Source of the price (URL, agreement…)')
                            "
                        />
                        <InputError
                            :message="
                                priceForm.errors.amount ??
                                priceForm.errors.component
                            "
                        />
                        <Button
                            type="submit"
                            variant="outline"
                            :disabled="priceForm.processing"
                            class="justify-self-start"
                            >{{ t('Save price') }}</Button
                        >
                    </form>
                </CardContent>
            </Card>
        </div>

        <Card>
            <CardHeader>
                <CardTitle>{{ t('Price history') }}</CardTitle>
            </CardHeader>
            <CardContent class="overflow-x-auto">
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>{{ t('Component') }}</TableHead>
                            <TableHead>{{ t('Price') }}</TableHead>
                            <TableHead>{{ t('Source') }}</TableHead>
                            <TableHead>{{ t('From') }}</TableHead>
                            <TableHead>{{ t('To') }}</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        <TableRow v-for="price in priceHistory" :key="price.id">
                            <TableCell
                                >{{
                                    t(
                                        priceComponentLabels[price.component] ??
                                            price.component,
                                    )
                                }}
                                <span
                                    v-if="price.tier !== 'standard'"
                                    class="text-xs text-muted-foreground"
                                    >({{ price.tier }})</span
                                ></TableCell
                            >
                            <TableCell
                                >{{ formatPrice(price.amount) }}
                                {{
                                    t(priceUnitLabels[price.unit] ?? price.unit)
                                }}</TableCell
                            >
                            <TableCell class="text-sm"
                                >{{ price.source ? t(price.source.name) : '' }}
                                <div
                                    v-if="price.notes"
                                    class="max-w-xs truncate text-xs text-muted-foreground"
                                    :title="price.notes"
                                >
                                    {{ price.notes }}
                                </div></TableCell
                            >
                            <TableCell class="text-sm text-muted-foreground">{{
                                formatDate(price.effective_from)
                            }}</TableCell>
                            <TableCell class="text-sm text-muted-foreground">{{
                                price.effective_to
                                    ? formatDate(price.effective_to)
                                    : t('Current')
                            }}</TableCell>
                        </TableRow>
                        <TableEmpty v-if="!priceHistory.length" :colspan="5">{{
                            t('No prices yet.')
                        }}</TableEmpty>
                    </TableBody>
                </Table>
            </CardContent>
        </Card>

        <Card>
            <CardHeader>
                <CardTitle>{{ t('Recent calls') }}</CardTitle>
            </CardHeader>
            <CardContent class="overflow-x-auto">
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>{{ t('Date') }}</TableHead>
                            <TableHead>{{ t('Feature') }}</TableHead>
                            <TableHead>{{ t('Tokens') }}</TableHead>
                            <TableHead>{{ t('Cost') }}</TableHead>
                            <TableHead>{{ t('Status') }}</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        <TableRow
                            v-for="request in recentRequests"
                            :key="request.id"
                        >
                            <TableCell class="text-sm text-muted-foreground">{{
                                formatDate(request.created_at)
                            }}</TableCell>
                            <TableCell class="text-sm">{{
                                t(request.feature)
                            }}</TableCell>
                            <TableCell class="text-sm">{{
                                formatNumber(
                                    request.input_tokens +
                                        request.output_tokens,
                                )
                            }}</TableCell>
                            <TableCell class="text-sm"
                                >{{ formatCost(request.cost) }}
                                <span class="text-xs text-muted-foreground"
                                    >({{
                                        t(
                                            costStatusLabels[
                                                request.cost_status
                                            ] ?? request.cost_status,
                                        )
                                    }})</span
                                ></TableCell
                            >
                            <TableCell
                                ><Badge variant="outline">{{
                                    t(request.status)
                                }}</Badge></TableCell
                            >
                        </TableRow>
                        <TableEmpty
                            v-if="!recentRequests.length"
                            :colspan="5"
                            >{{ t('No calls yet.') }}</TableEmpty
                        >
                    </TableBody>
                </Table>
            </CardContent>
        </Card>
    </div>
</template>
