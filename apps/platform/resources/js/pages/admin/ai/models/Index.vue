<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { watchDebounced } from '@vueuse/core';
import { CircleCheck, Plus, Radar, Search, Star } from '@lucide/vue';
import { reactive, ref } from 'vue';
import InputError from '@/components/InputError.vue';
import PageHeader from '@/components/PageHeader.vue';
import Pagination from '@/components/Pagination.vue';
import { Badge } from '@starter/ui/components/badge';
import { Button } from '@starter/ui/components/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
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
import { formatContext, formatPrice, modelStatusVariant } from '@/lib/ai';
import { t } from '@/lib/i18n';
import connectionsRoutes from '@/routes/admin/ai/connections';
import models from '@/routes/admin/ai/models';
import type { AiCatalogModel, Paginated } from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Model catalog', href: models.index() }],
    },
});

const props = defineProps<{
    models: Paginated<AiCatalogModel>;
    connections: { id: number; label: string; discovery: boolean }[];
    functions: string[];
    capabilities: { value: string; label: string }[];
    filters: {
        search?: string;
        connection?: string;
        function?: string;
        capability?: string;
        status?: string;
        enabled?: string;
        sort?: string;
    };
}>();

const filters = reactive({
    search: props.filters.search ?? '',
    connection: props.filters.connection
        ? String(props.filters.connection)
        : 'all',
    function: props.filters.function ?? 'all',
    capability: props.filters.capability ?? 'all',
    status: props.filters.status ?? 'all',
    enabled: props.filters.enabled ?? 'all',
    sort: props.filters.sort ?? 'name',
});

watchDebounced(
    filters,
    () => {
        const query: Record<string, string> = {};

        for (const [key, value] of Object.entries(filters)) {
            if (
                value &&
                value !== 'all' &&
                !(key === 'sort' && value === 'name')
            ) {
                query[key] = value;
            }
        }

        router.get(models.index().url, query, {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });
    },
    { debounce: 300, deep: true },
);

const discoverConnection = ref<string>(
    String(props.connections.find((item) => item.discovery)?.id ?? ''),
);

const discover = () =>
    discoverConnection.value &&
    router.post(
        connectionsRoutes.discover(Number(discoverConnection.value)).url,
        {},
        { preserveScroll: true },
    );

const toggle = (model: AiCatalogModel, value: boolean) =>
    router.put(
        models.update(model.id).url,
        { is_enabled: value },
        { preserveScroll: true },
    );

const verify = (model: AiCatalogModel) =>
    router.post(models.verify(model.id).url, {}, { preserveScroll: true });

const importOpen = ref(false);
const importForm = useForm({
    connection_id: String(props.connections[0]?.id ?? ''),
    model: '',
    label: '',
    functions: ['text'],
    source_url: '',
});

const submitImport = () =>
    importForm.post(models.store().url, {
        onSuccess: () => {
            importOpen.value = false;
            importForm.reset();
        },
    });

const statusLabels: Record<string, string> = {
    unknown: 'Not verified',
    available: 'Available',
    unavailable: 'Unavailable',
    deprecated: 'Deprecated',
};
</script>

<template>
    <Head :title="t('Model catalog')" />

    <div class="flex flex-col gap-6 p-4 md:p-6">
        <PageHeader
            :title="t('Model catalog')"
            :description="
                t(
                    'Models reachable through your connections. Being listed does not prove access: verify a model with a minimal call.',
                )
            "
        >
            <div class="flex flex-wrap items-center gap-2">
                <Select v-model="discoverConnection">
                    <SelectTrigger class="w-44"
                        ><SelectValue :placeholder="t('Connection')"
                    /></SelectTrigger>
                    <SelectContent>
                        <SelectItem
                            v-for="connection in connections.filter(
                                (item) => item.discovery,
                            )"
                            :key="connection.id"
                            :value="String(connection.id)"
                            >{{ connection.label }}</SelectItem
                        >
                    </SelectContent>
                </Select>
                <Button
                    variant="outline"
                    :disabled="!discoverConnection"
                    @click="discover"
                >
                    <Radar class="size-4" />
                    {{ t('Discover') }}
                </Button>
                <Dialog v-model:open="importOpen">
                    <DialogTrigger as-child>
                        <Button :disabled="!connections.length">
                            <Plus class="size-4" />
                            {{ t('Add model') }}
                        </Button>
                    </DialogTrigger>
                    <DialogContent>
                        <form class="grid gap-4" @submit.prevent="submitImport">
                            <DialogHeader>
                                <DialogTitle>{{
                                    t('Add a model manually')
                                }}</DialogTitle>
                                <DialogDescription>{{
                                    t(
                                        'Use the exact identifier of the provider and note where it comes from.',
                                    )
                                }}</DialogDescription>
                            </DialogHeader>
                            <div class="grid gap-2">
                                <Label>{{ t('Connection') }}</Label>
                                <Select v-model="importForm.connection_id">
                                    <SelectTrigger class="w-full"
                                        ><SelectValue
                                    /></SelectTrigger>
                                    <SelectContent>
                                        <SelectItem
                                            v-for="connection in connections"
                                            :key="connection.id"
                                            :value="String(connection.id)"
                                            >{{ connection.label }}</SelectItem
                                        >
                                    </SelectContent>
                                </Select>
                                <InputError
                                    :message="importForm.errors.connection_id"
                                />
                            </div>
                            <div class="grid gap-2">
                                <Label for="import-model">{{
                                    t('Model identifier')
                                }}</Label>
                                <Input
                                    id="import-model"
                                    v-model="importForm.model"
                                    required
                                    class="font-mono"
                                />
                                <InputError
                                    :message="importForm.errors.model"
                                />
                            </div>
                            <div class="grid gap-2">
                                <Label for="import-label">{{
                                    t('Label')
                                }}</Label>
                                <Input
                                    id="import-label"
                                    v-model="importForm.label"
                                />
                            </div>
                            <div class="grid gap-2">
                                <Label for="import-source">{{
                                    t('Source')
                                }}</Label>
                                <Input
                                    id="import-source"
                                    v-model="importForm.source_url"
                                    type="url"
                                    placeholder="https://…"
                                />
                                <InputError
                                    :message="importForm.errors.source_url"
                                />
                            </div>
                            <DialogFooter>
                                <Button
                                    type="submit"
                                    :disabled="importForm.processing"
                                    >{{ t('Add model') }}</Button
                                >
                            </DialogFooter>
                        </form>
                    </DialogContent>
                </Dialog>
            </div>
        </PageHeader>

        <div class="grid gap-3 sm:grid-cols-3 lg:grid-cols-6">
            <div class="relative sm:col-span-3 lg:col-span-2">
                <Search
                    class="absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                />
                <Input
                    v-model="filters.search"
                    class="pl-9"
                    :placeholder="t('Search models')"
                />
            </div>
            <Select v-model="filters.connection">
                <SelectTrigger class="w-full"><SelectValue /></SelectTrigger>
                <SelectContent>
                    <SelectItem value="all">{{
                        t('All connections')
                    }}</SelectItem>
                    <SelectItem
                        v-for="connection in connections"
                        :key="connection.id"
                        :value="String(connection.id)"
                        >{{ connection.label }}</SelectItem
                    >
                </SelectContent>
            </Select>
            <Select v-model="filters.function">
                <SelectTrigger class="w-full"><SelectValue /></SelectTrigger>
                <SelectContent>
                    <SelectItem value="all">{{
                        t('All functions')
                    }}</SelectItem>
                    <SelectItem v-for="fn in functions" :key="fn" :value="fn">{{
                        t(fn)
                    }}</SelectItem>
                </SelectContent>
            </Select>
            <Select v-model="filters.capability">
                <SelectTrigger class="w-full"><SelectValue /></SelectTrigger>
                <SelectContent>
                    <SelectItem value="all">{{
                        t('Any capability')
                    }}</SelectItem>
                    <SelectItem
                        v-for="capability in capabilities"
                        :key="capability.value"
                        :value="capability.value"
                        >{{ t(capability.label) }}</SelectItem
                    >
                </SelectContent>
            </Select>
            <Select v-model="filters.sort">
                <SelectTrigger class="w-full"><SelectValue /></SelectTrigger>
                <SelectContent>
                    <SelectItem value="name">{{
                        t('Sort by name')
                    }}</SelectItem>
                    <SelectItem value="input_price">{{
                        t('Sort by input price')
                    }}</SelectItem>
                    <SelectItem value="context">{{
                        t('Sort by context')
                    }}</SelectItem>
                </SelectContent>
            </Select>
        </div>

        <div class="rounded-lg border">
            <Table>
                <TableHeader>
                    <TableRow>
                        <TableHead>{{ t('Model') }}</TableHead>
                        <TableHead class="hidden md:table-cell">{{
                            t('Context')
                        }}</TableHead>
                        <TableHead class="hidden lg:table-cell">{{
                            t('Input / output (1M)')
                        }}</TableHead>
                        <TableHead>{{ t('Status') }}</TableHead>
                        <TableHead>{{ t('Enabled') }}</TableHead>
                        <TableHead class="w-12 text-right">
                            <span class="sr-only">{{ t('Actions') }}</span>
                        </TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    <TableRow
                        v-for="model in props.models.data"
                        :key="model.id"
                    >
                        <TableCell>
                            <Link
                                :href="models.show(model.id)"
                                class="font-medium hover:underline"
                            >
                                {{ model.name }}
                            </Link>
                            <Star
                                v-if="model.is_default"
                                class="ml-1 inline size-3.5 fill-current text-amber-500"
                                :aria-label="t('Default')"
                            />
                            <div
                                class="font-mono text-xs text-muted-foreground"
                            >
                                {{ model.model }}
                            </div>
                            <div class="text-xs text-muted-foreground">
                                {{ model.connection?.label }}
                            </div>
                        </TableCell>
                        <TableCell class="hidden text-sm md:table-cell">{{
                            formatContext(model.context_window)
                        }}</TableCell>
                        <TableCell class="hidden text-sm lg:table-cell">
                            {{
                                model.prices?.input
                                    ? formatPrice(model.prices.input.amount)
                                    : '—'
                            }}
                            /
                            {{
                                model.prices?.output
                                    ? formatPrice(model.prices.output.amount)
                                    : '—'
                            }}
                        </TableCell>
                        <TableCell>
                            <Badge
                                :variant="
                                    modelStatusVariant[model.status] ??
                                    'outline'
                                "
                                :title="model.last_error ?? ''"
                            >
                                {{
                                    t(
                                        statusLabels[model.status] ??
                                            model.status,
                                    )
                                }}
                            </Badge>
                        </TableCell>
                        <TableCell>
                            <Switch
                                :model-value="model.is_enabled"
                                :aria-label="t('Enabled')"
                                @update:model-value="
                                    (value) => toggle(model, Boolean(value))
                                "
                            />
                        </TableCell>
                        <TableCell class="text-right">
                            <Button
                                variant="ghost"
                                size="icon"
                                :title="t('Verify')"
                                :aria-label="t('Verify')"
                                @click="verify(model)"
                            >
                                <CircleCheck class="size-4" />
                            </Button>
                        </TableCell>
                    </TableRow>
                    <TableEmpty v-if="!props.models.data.length" :colspan="6">
                        {{
                            t(
                                'No models found. Discover them from a connection or add one manually.',
                            )
                        }}
                    </TableEmpty>
                </TableBody>
            </Table>
        </div>

        <Pagination :meta="props.models.meta" />
    </div>
</template>
