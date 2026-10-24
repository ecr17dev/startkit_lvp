<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import type {
    PromptFormData,
    PromptModelOption,
} from '@/components/admin/PromptForm.vue';
import PromptForm from '@/components/admin/PromptForm.vue';
import PageHeader from '@/components/PageHeader.vue';
import { Button } from '@starter/ui/components/button';
import { Spinner } from '@starter/ui/components/spinner';
import { emptyToNull } from '@/lib/forms';
import { t } from '@/lib/i18n';
import { edit, index, update } from '@/routes/admin/ai/prompts';

type PromptData = {
    id: number;
    name: string;
    slug: string;
    description: string | null;
    content: string;
    ai_model_id: number | null;
    temperature: string | null;
    max_tokens: number | null;
    is_default: boolean;
    is_active: boolean;
};

const props = defineProps<{
    prompt: PromptData;
    models: PromptModelOption[];
    variables: string[];
}>();

defineOptions({
    layout: (props: { prompt: PromptData }) => ({
        breadcrumbs: [
            { title: 'System prompts', href: index() },
            { title: props.prompt.name, href: edit(props.prompt.id) },
        ],
    }),
});

const form = useForm<PromptFormData>({
    name: props.prompt.name,
    slug: props.prompt.slug,
    description: props.prompt.description ?? '',
    content: props.prompt.content,
    ai_model_id: props.prompt.ai_model_id ?? '',
    temperature: props.prompt.temperature ?? '',
    max_tokens: props.prompt.max_tokens ?? '',
    is_default: props.prompt.is_default,
    is_active: props.prompt.is_active,
});

const submit = () =>
    form
        .transform((data) =>
            emptyToNull(data, ['ai_model_id', 'temperature', 'max_tokens']),
        )
        .put(update(props.prompt.id).url, { preserveScroll: true });
</script>

<template>
    <Head :title="prompt.name" />

    <form
        class="flex max-w-4xl flex-col gap-6 p-4 md:p-6"
        @submit.prevent="submit"
    >
        <PageHeader
            :title="prompt.name"
            :description="prompt.description ?? undefined"
        />

        <PromptForm :form="form" :models="models" :variables="variables" />

        <div class="flex items-center gap-2">
            <Button type="submit" :disabled="form.processing">
                <Spinner v-if="form.processing" />
                {{ t('Save changes') }}
            </Button>
            <Button variant="ghost" as-child>
                <Link :href="index()">{{ t('Cancel') }}</Link>
            </Button>
        </div>
    </form>
</template>
