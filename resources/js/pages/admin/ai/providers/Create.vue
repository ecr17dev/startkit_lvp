<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { watch } from 'vue';
import type { ProviderFormData } from '@/components/admin/ProviderForm.vue';
import ProviderForm from '@/components/admin/ProviderForm.vue';
import PageHeader from '@/components/PageHeader.vue';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { t } from '@/lib/i18n';
import { create, index, store } from '@/routes/admin/ai/providers';
import type { ProviderDriverOption } from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Providers & models', href: index() },
            { title: 'Add provider', href: create() },
        ],
    },
});

const props = defineProps<{
    drivers: ProviderDriverOption[];
}>();

const form = useForm<ProviderFormData>({
    label: '',
    name: '',
    driver: 'openai',
    api_key: '',
    base_url: '',
    default_model: '',
    is_active: true,
    is_default: false,
    import_models: true,
    options: {
        region: 'us-east-1',
        access_key_id: '',
        secret_access_key: '',
        session_token: '',
        api_version: '2025-04-01-preview',
        deployment: '',
    },
});

// Suggest a display name from the selected driver...
watch(
    () => form.driver,
    (driver, previous) => {
        const previousLabel = props.drivers.find(
            (item) => item.value === previous,
        )?.label;

        if (!form.label || form.label === previousLabel) {
            form.label =
                props.drivers.find((item) => item.value === driver)?.label ??
                '';
        }
    },
    { immediate: true },
);

const submit = () => form.post(store().url);
</script>

<template>
    <Head :title="t('Add provider')" />

    <form
        class="flex max-w-3xl flex-col gap-6 p-4 md:p-6"
        @submit.prevent="submit"
    >
        <PageHeader
            :title="t('Add provider')"
            :description="
                t('Credentials are stored encrypted with the application key')
            "
        />

        <ProviderForm :form="form" :drivers="drivers" />

        <div class="flex items-center gap-2">
            <Button type="submit" :disabled="form.processing">
                <Spinner v-if="form.processing" />
                {{ t('Save provider') }}
            </Button>
            <Button variant="ghost" as-child>
                <Link :href="index()">{{ t('Cancel') }}</Link>
            </Button>
        </div>
    </form>
</template>
