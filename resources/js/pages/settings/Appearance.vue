<script setup lang="ts">
import { Head, router, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import AppearanceTabs from '@/components/AppearanceTabs.vue';
import Heading from '@/components/Heading.vue';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { t } from '@/lib/i18n';
import { edit } from '@/routes/appearance';
import { update as updateLocale } from '@/routes/locale';

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Appearance settings',
                href: edit(),
            },
        ],
    },
});

const page = usePage();
const locales = computed(() => page.props.locales ?? {});

const changeLocale = (value: unknown) => {
    if (typeof value === 'string') {
        router.post(
            updateLocale(),
            { locale: value },
            { preserveScroll: true },
        );
    }
};
</script>

<template>
    <Head :title="t('Appearance settings')" />

    <h1 class="sr-only">{{ t('Appearance settings') }}</h1>

    <div class="space-y-6">
        <Heading
            variant="small"
            :title="t('Appearance settings')"
            :description="t('Update the appearance settings for your account')"
        />
        <AppearanceTabs />
    </div>

    <div class="space-y-6">
        <Heading
            variant="small"
            :title="t('Language')"
            :description="t('Choose the language of the interface')"
        />
        <Select
            :model-value="page.props.locale"
            @update:model-value="changeLocale"
        >
            <SelectTrigger class="w-56" data-test="locale-select">
                <SelectValue />
            </SelectTrigger>
            <SelectContent>
                <SelectItem
                    v-for="(label, code) in locales"
                    :key="code"
                    :value="code"
                >
                    {{ label }}
                </SelectItem>
            </SelectContent>
        </Select>
    </div>
</template>
