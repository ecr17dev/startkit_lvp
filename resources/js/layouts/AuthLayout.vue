<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import AuthCardLayout from '@/layouts/auth/AuthCardLayout.vue';
import AuthSimpleLayout from '@/layouts/auth/AuthSimpleLayout.vue';
import AuthSplitLayout from '@/layouts/auth/AuthSplitLayout.vue';
import { t } from '@/lib/i18n';

const { title = '', description = '' } = defineProps<{
    title?: string;
    description?: string;
}>();

const page = usePage();

// The layout is chosen from Admin → System settings → Branding...
const layout = computed(() => {
    switch (page.props.branding?.authLayout) {
        case 'card':
            return AuthCardLayout;
        case 'split':
            return AuthSplitLayout;
        default:
            return AuthSimpleLayout;
    }
});
</script>

<template>
    <component
        :is="layout"
        :title="title ? t(title) : ''"
        :description="description ? t(description) : ''"
    >
        <slot />
    </component>
</template>
