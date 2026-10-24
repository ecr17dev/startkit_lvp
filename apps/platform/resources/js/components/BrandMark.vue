<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import AppLogoIcon from '@/components/AppLogoIcon.vue';

withDefaults(defineProps<{ size?: string }>(), { size: 'size-9' });

const page = usePage();
const branding = computed(() => page.props.branding);
</script>

<template>
    <template v-if="branding.logoUrl">
        <img
            :src="branding.logoUrl"
            :alt="branding.appName"
            :class="[
                size,
                'object-contain',
                { 'dark:hidden': branding.logoDarkUrl },
            ]"
        />
        <img
            v-if="branding.logoDarkUrl"
            :src="branding.logoDarkUrl"
            :alt="branding.appName"
            :class="[size, 'hidden object-contain dark:block']"
        />
    </template>
    <AppLogoIcon
        v-else
        :class="[size, 'fill-current text-[var(--foreground)] dark:text-white']"
    />
</template>
