<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ChevronRight, FilePlus2 } from '@lucide/vue';
import { computed, ref } from 'vue';
import { FormField, Modal, SubmitButton } from '@/components/kit';
import PageHeader from '@/components/PageHeader.vue';
import { Badge } from '@starter/ui/components/badge';
import { Button } from '@starter/ui/components/button';
import { Input } from '@starter/ui/components/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@starter/ui/components/select';
import { usePermissions } from '@/composables/usePermissions';
import { t } from '@/lib/i18n';
import { edit, index, store } from '@/routes/admin/mail/templates';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Mail', href: index() }],
    },
});

type TemplateRow = {
    key: string;
    category: 'access' | 'ai' | 'automation' | 'system' | 'custom';
    name: string;
    description: string;
    customized: string[];
    custom: boolean;
};

const props = defineProps<{
    templates: TemplateRow[];
    locales: Record<string, string>;
}>();

const { can } = usePermissions();

const categories = [
    {
        key: 'access',
        title: 'Access',
        description: 'Login codes, invitations and password reset.',
    },
    {
        key: 'automation',
        title: 'Automations',
        description: 'Starting points for the mail automations.',
    },
    {
        key: 'custom',
        title: 'Custom',
        description: 'Templates created here for your automations.',
    },
    {
        key: 'ai',
        title: 'Artificial intelligence',
        description: 'Alerts of the AI budgets.',
    },
    {
        key: 'system',
        title: 'System',
        description: 'Messages of the platform itself.',
    },
] as const;

const groups = computed(() =>
    categories
        .map((category) => ({
            ...category,
            templates: props.templates.filter(
                (template) => template.category === category.key,
            ),
        }))
        .filter((group) => group.templates.length > 0),
);

const open = ref(false);
const form = useForm({
    name: '',
    locale: Object.keys(props.locales)[0] ?? 'es',
    from: 'automation.announcement',
});

const create = () =>
    form.post(store().url, { onSuccess: () => (open.value = false) });
</script>

<template>
    <Head :title="t('Email templates')" />

    <PageHeader
        :title="t('Email templates')"
        :description="
            t(
                'Every email the platform sends. Edit the text, use variables and preview it before saving.',
            )
        "
    >
        <Modal
            v-if="can('mail.manage')"
            v-model:open="open"
            :title="t('New template')"
            :description="
                t('Custom templates can be used by the mail automations.')
            "
        >
            <template #trigger>
                <Button data-test="new-template">
                    <FilePlus2 />
                    {{ t('New template') }}
                </Button>
            </template>

            <form id="new-template" class="grid gap-4" @submit.prevent="create">
                <FormField
                    :label="t('Name')"
                    :error="form.errors.name"
                    required
                    v-slot="field"
                >
                    <Input
                        v-model="form.name"
                        v-bind="field"
                        :placeholder="t('Monthly newsletter')"
                    />
                </FormField>
                <FormField
                    :label="t('Language')"
                    :error="form.errors.locale"
                    v-slot="field"
                >
                    <Select v-model="form.locale">
                        <SelectTrigger v-bind="field" class="w-full">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem
                                v-for="(label, code) in locales"
                                :key="code"
                                :value="code"
                                >{{ label }}</SelectItem
                            >
                        </SelectContent>
                    </Select>
                </FormField>
                <FormField
                    :label="t('Start from')"
                    :error="form.errors.from"
                    v-slot="field"
                >
                    <Select v-model="form.from">
                        <SelectTrigger v-bind="field" class="w-full">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem
                                v-for="template in templates"
                                :key="template.key"
                                :value="template.key"
                                >{{ template.name }}</SelectItem
                            >
                        </SelectContent>
                    </Select>
                </FormField>
            </form>

            <template #footer>
                <SubmitButton form="new-template" :processing="form.processing">
                    {{ t('Create template') }}
                </SubmitButton>
            </template>
        </Modal>
    </PageHeader>

    <section
        v-for="group in groups"
        :key="group.key"
        class="space-y-3"
        :aria-labelledby="`group-${group.key}`"
    >
        <div>
            <h2 :id="`group-${group.key}`" class="text-sm font-semibold">
                {{ t(group.title) }}
            </h2>
            <p class="text-sm text-muted-foreground">
                {{ t(group.description) }}
            </p>
        </div>

        <ul class="divide-y rounded-lg border">
            <li v-for="template in group.templates" :key="template.key">
                <Link
                    :href="edit(template.key)"
                    class="flex items-center gap-4 p-4 transition-colors hover:bg-muted/50 focus-visible:bg-muted/50 focus-visible:outline-none"
                    :data-test="`template-${template.key}`"
                >
                    <div class="min-w-0 flex-1 space-y-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="font-medium">{{ template.name }}</span>
                            <Badge
                                v-for="locale in template.customized"
                                :key="locale"
                                variant="secondary"
                                >{{
                                    template.custom
                                        ? locale.toUpperCase()
                                        : t('Customized :locale', {
                                              locale: locale.toUpperCase(),
                                          })
                                }}</Badge
                            >
                        </div>
                        <p
                            v-if="template.description"
                            class="text-sm text-muted-foreground"
                        >
                            {{ template.description }}
                        </p>
                        <code class="text-xs text-muted-foreground">{{
                            template.key
                        }}</code>
                    </div>
                    <ChevronRight
                        class="size-4 shrink-0 text-muted-foreground"
                    />
                </Link>
            </li>
        </ul>
    </section>
</template>
