<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { CircleAlert, Pencil, Plug, Plus, Trash2 } from '@lucide/vue';
import { computed, ref } from 'vue';
import ModelDialog from '@/components/admin/ModelDialog.vue';
import type { ProviderFormData } from '@/components/admin/ProviderForm.vue';
import ProviderForm from '@/components/admin/ProviderForm.vue';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import Heading from '@/components/Heading.vue';
import PageHeader from '@/components/PageHeader.vue';
import { Badge } from '@starter/ui/components/badge';
import { Button } from '@starter/ui/components/button';
import { Spinner } from '@starter/ui/components/spinner';
import {
    Table,
    TableBody,
    TableCell,
    TableEmpty,
    TableHead,
    TableHeader,
    TableRow,
} from '@starter/ui/components/table';
import { formatNumber, t } from '@/lib/i18n';
import {
    destroy as destroyModel,
    store as storeModel,
} from '@/routes/admin/ai/models';
import { edit, index, test, update } from '@/routes/admin/ai/providers';
import type {
    AiModelData,
    AiProviderData,
    ProviderDriverOption,
    SuggestedModel,
} from '@/types';

const props = defineProps<{
    provider: AiProviderData;
    models: AiModelData[];
    drivers: ProviderDriverOption[];
    suggestedModels: SuggestedModel[];
}>();

defineOptions({
    layout: (props: { provider: AiProviderData }) => ({
        breadcrumbs: [
            { title: 'Providers & models', href: index() },
            { title: props.provider.label, href: edit(props.provider.id) },
        ],
    }),
});

const option = (key: string) => {
    const value = props.provider.options[key];

    return typeof value === 'string' ? value : '';
};

const form = useForm<ProviderFormData & { remove_api_key: boolean }>({
    label: props.provider.label,
    name: props.provider.name,
    driver: props.provider.driver,
    api_key: '',
    remove_api_key: false,
    base_url: props.provider.base_url ?? '',
    default_model: props.provider.default_model ?? '',
    is_active: props.provider.is_active,
    is_default: props.provider.is_default,
    import_models: false,
    options: {
        region: option('region') || 'us-east-1',
        access_key_id: option('access_key_id'),
        secret_access_key: '',
        session_token: '',
        api_version: option('api_version'),
        deployment: option('deployment'),
    },
});

const submit = () =>
    form.put(update(props.provider.id).url, {
        preserveScroll: true,
        onSuccess: () => form.reset('api_key'),
    });

const testing = ref(false);
const runTest = () =>
    router.post(
        test(props.provider.id).url,
        {},
        {
            preserveScroll: true,
            onStart: () => (testing.value = true),
            onFinish: () => (testing.value = false),
        },
    );

const dialogOpen = ref(false);
const editingModel = ref<AiModelData | null>(null);

const openModel = (model: AiModelData | null) => {
    editingModel.value = model;
    dialogOpen.value = true;
};

const removeModel = (model: AiModelData) =>
    router.delete(destroyModel(model.id).url, { preserveScroll: true });

const missingSuggestions = computed(() =>
    props.suggestedModels.filter(
        (suggestion) =>
            !props.models.some((model) => model.model === suggestion.model),
    ),
);

const addSuggestion = (suggestion: SuggestedModel) =>
    router.post(
        storeModel(props.provider.id).url,
        { ...suggestion, is_active: true },
        { preserveScroll: true },
    );

const price = (value: string | null) =>
    value === null ? '—' : `$${Number(value).toString()}`;
</script>

<template>
    <Head :title="provider.label" />

    <div class="flex max-w-5xl flex-col gap-10 p-4 md:p-6">
        <form class="flex max-w-3xl flex-col gap-6" @submit.prevent="submit">
            <PageHeader
                :title="provider.label"
                :description="provider.driver_label"
            >
                <Button
                    type="button"
                    variant="outline"
                    :disabled="testing"
                    @click="runTest"
                >
                    <Spinner v-if="testing" />
                    <Plug v-else class="size-4" />
                    {{ t('Test connection') }}
                </Button>
            </PageHeader>

            <ProviderForm
                :form="form"
                :drivers="drivers"
                editing
                :api-key-masked="provider.api_key_masked"
                :secrets="{
                    has_secret_access_key:
                        !!provider.options.has_secret_access_key,
                    has_session_token: !!provider.options.has_session_token,
                }"
            />

            <div class="flex items-center gap-2">
                <Button type="submit" :disabled="form.processing">
                    <Spinner v-if="form.processing" />
                    {{ t('Save changes') }}
                </Button>
                <Button variant="ghost" as-child>
                    <Link :href="index()">{{ t('Back') }}</Link>
                </Button>
            </div>
        </form>

        <div class="flex flex-col gap-4">
            <div class="flex flex-wrap items-end justify-between gap-2">
                <Heading
                    variant="small"
                    :title="t('Models & pricing')"
                    :description="
                        t(
                            'USD per million tokens. Calls to models without price are logged with cost 0.',
                        )
                    "
                />
                <Button size="sm" @click="openModel(null)">
                    <Plus class="size-4" />
                    {{ t('Add model') }}
                </Button>
            </div>

            <div class="rounded-lg border">
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>{{ t('Model') }}</TableHead>
                            <TableHead class="text-right">{{
                                t('Input')
                            }}</TableHead>
                            <TableHead class="text-right">{{
                                t('Output')
                            }}</TableHead>
                            <TableHead
                                class="hidden text-right md:table-cell"
                                >{{ t('Cache read') }}</TableHead
                            >
                            <TableHead
                                class="hidden text-right md:table-cell"
                                >{{ t('Context') }}</TableHead
                            >
                            <TableHead class="w-24" />
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        <TableRow v-for="model in models" :key="model.id">
                            <TableCell>
                                <div class="flex items-center gap-2">
                                    <span class="font-mono text-xs">{{
                                        model.model
                                    }}</span>
                                    <Badge
                                        v-if="
                                            model.model ===
                                            provider.default_model
                                        "
                                        variant="secondary"
                                        >{{ t('Default') }}</Badge
                                    >
                                    <Badge
                                        v-if="!model.is_active"
                                        variant="outline"
                                        >{{ t('Hidden') }}</Badge
                                    >
                                    <CircleAlert
                                        v-if="!model.is_priced"
                                        class="size-4 text-amber-500"
                                        :aria-label="t('No price configured')"
                                    />
                                </div>
                                <div
                                    v-if="model.label"
                                    class="text-xs text-muted-foreground"
                                >
                                    {{ model.label }}
                                </div>
                            </TableCell>
                            <TableCell class="text-right font-mono text-xs">{{
                                price(model.input_price)
                            }}</TableCell>
                            <TableCell class="text-right font-mono text-xs">{{
                                price(model.output_price)
                            }}</TableCell>
                            <TableCell
                                class="hidden text-right font-mono text-xs md:table-cell"
                                >{{ price(model.cache_read_price) }}</TableCell
                            >
                            <TableCell
                                class="hidden text-right text-xs md:table-cell"
                                >{{
                                    model.context_window
                                        ? formatNumber(model.context_window)
                                        : '—'
                                }}</TableCell
                            >
                            <TableCell class="text-right">
                                <div class="flex justify-end">
                                    <Button
                                        variant="ghost"
                                        size="icon"
                                        :aria-label="t('Edit')"
                                        @click="openModel(model)"
                                    >
                                        <Pencil class="size-4" />
                                    </Button>
                                    <ConfirmDialog
                                        :title="t('Delete model')"
                                        :description="
                                            t(
                                                'The model and its prices will be deleted.',
                                            )
                                        "
                                        :confirm-text="t('Delete')"
                                        @confirm="removeModel(model)"
                                    >
                                        <Button
                                            variant="ghost"
                                            size="icon"
                                            class="text-destructive"
                                            :aria-label="t('Delete')"
                                        >
                                            <Trash2 class="size-4" />
                                        </Button>
                                    </ConfirmDialog>
                                </div>
                            </TableCell>
                        </TableRow>
                        <TableEmpty v-if="!models.length" :colspan="6">
                            {{
                                t(
                                    'No models yet. Add the models you use to track their costs.',
                                )
                            }}
                        </TableEmpty>
                    </TableBody>
                </Table>
            </div>

            <div
                v-if="missingSuggestions.length"
                class="flex flex-wrap items-center gap-2"
            >
                <span class="text-sm text-muted-foreground"
                    >{{ t('Suggested') }}:</span
                >
                <Button
                    v-for="suggestion in missingSuggestions"
                    :key="suggestion.model"
                    variant="outline"
                    size="sm"
                    @click="addSuggestion(suggestion)"
                >
                    <Plus class="size-3" />
                    {{ suggestion.label }}
                </Button>
            </div>
        </div>
    </div>

    <ModelDialog
        v-model:open="dialogOpen"
        :provider-id="provider.id"
        :model="editingModel"
    />
</template>
