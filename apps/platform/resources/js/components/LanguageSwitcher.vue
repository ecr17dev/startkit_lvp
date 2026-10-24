<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { Languages } from '@lucide/vue';
import { computed } from 'vue';
import { Button } from '@starter/ui/components/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuRadioGroup,
    DropdownMenuRadioItem,
    DropdownMenuTrigger,
} from '@starter/ui/components/dropdown-menu';
import { t } from '@/lib/i18n';
import { update } from '@/routes/locale';

const page = usePage();
const locales = computed(() => page.props.locales ?? {});
const locale = computed(() => page.props.locale);

const change = (value: unknown) => {
    if (typeof value !== 'string' || value === locale.value) {
        return;
    }

    router.post(update(), { locale: value }, { preserveScroll: true });
};
</script>

<template>
    <DropdownMenu v-if="Object.keys(locales).length > 1">
        <DropdownMenuTrigger as-child>
            <Button variant="ghost" size="sm" :aria-label="t('Language')">
                <Languages class="size-4" />
                <span class="uppercase">{{ locale }}</span>
            </Button>
        </DropdownMenuTrigger>
        <DropdownMenuContent align="end">
            <DropdownMenuRadioGroup
                :model-value="locale"
                @update:model-value="change"
            >
                <DropdownMenuRadioItem
                    v-for="(label, code) in locales"
                    :key="code"
                    :value="code"
                >
                    {{ label }}
                </DropdownMenuRadioItem>
            </DropdownMenuRadioGroup>
        </DropdownMenuContent>
    </DropdownMenu>
</template>
