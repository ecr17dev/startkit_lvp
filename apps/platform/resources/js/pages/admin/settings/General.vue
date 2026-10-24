<script setup lang="ts">
import { Head, useForm, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@starter/ui/components/button';
import { Input } from '@starter/ui/components/input';
import { Label } from '@starter/ui/components/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@starter/ui/components/select';
import { t } from '@/lib/i18n';
import general from '@/routes/admin/settings/general';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'System settings', href: general.edit() }],
    },
});

const props = defineProps<{
    settings: { locale: string; support_email: string | null };
}>();

const page = usePage();
const locales = computed(() => page.props.locales ?? {});

const form = useForm({
    locale: props.settings.locale,
    support_email: props.settings.support_email ?? '',
});

const submit = () => form.put(general.update().url, { preserveScroll: true });
</script>

<template>
    <Head :title="t('General')" />

    <form class="space-y-6" @submit.prevent="submit">
        <Heading
            variant="small"
            :title="t('General')"
            :description="t('Default language and contact information')"
        />

        <div class="grid gap-2">
            <Label>{{ t('Default language') }}</Label>
            <Select v-model="form.locale">
                <SelectTrigger class="w-64">
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
            <p class="text-xs text-muted-foreground">
                {{
                    t(
                        'Used for visitors and users without a preferred language.',
                    )
                }}
            </p>
            <InputError :message="form.errors.locale" />
        </div>

        <div class="grid gap-2">
            <Label for="support_email">{{ t('Support email') }}</Label>
            <Input
                id="support_email"
                v-model="form.support_email"
                type="email"
                placeholder="soporte@empresa.com"
            />
            <InputError :message="form.errors.support_email" />
        </div>

        <Button type="submit" :disabled="form.processing">{{
            t('Save')
        }}</Button>
    </form>
</template>
