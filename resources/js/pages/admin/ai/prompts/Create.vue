<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import type {
    PromptFormData,
    PromptProviderOption,
} from '@/components/admin/PromptForm.vue';
import PromptForm from '@/components/admin/PromptForm.vue';
import PageHeader from '@/components/PageHeader.vue';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { emptyToNull } from '@/lib/forms';
import { t } from '@/lib/i18n';
import { create, index, store } from '@/routes/admin/ai/prompts';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'System prompts', href: index() },
            { title: 'New prompt', href: create() },
        ],
    },
});

defineProps<{
    providers: PromptProviderOption[];
    variables: string[];
}>();

const form = useForm<PromptFormData>({
    name: '',
    slug: '',
    description: '',
    content: '',
    ai_provider_id: '',
    model: '',
    temperature: '',
    max_tokens: '',
    is_default: false,
    is_active: true,
});

const submit = () =>
    form
        .transform((data) =>
            emptyToNull(data, [
                'ai_provider_id',
                'model',
                'temperature',
                'max_tokens',
            ]),
        )
        .post(store().url);
</script>

<template>
    <Head :title="t('New prompt')" />

    <form
        class="flex max-w-4xl flex-col gap-6 p-4 md:p-6"
        @submit.prevent="submit"
    >
        <PageHeader :title="t('New prompt')" />

        <PromptForm
            :form="form"
            :providers="providers"
            :variables="variables"
        />

        <div class="flex items-center gap-2">
            <Button type="submit" :disabled="form.processing">
                <Spinner v-if="form.processing" />
                {{ t('Create prompt') }}
            </Button>
            <Button variant="ghost" as-child>
                <Link :href="index()">{{ t('Cancel') }}</Link>
            </Button>
        </div>
    </form>
</template>
