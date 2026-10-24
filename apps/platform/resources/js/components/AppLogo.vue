<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import AppLogoIcon from '@/components/AppLogoIcon.vue';

const page = usePage();
const branding = computed(() => page.props.branding);
</script>

<template>
    <div
        v-if="branding.logoUrl"
        class="flex aspect-square size-8 items-center justify-center overflow-hidden rounded-md"
    >
        <img
            :src="branding.logoUrl"
            :alt="branding.appName"
            class="size-8 object-contain"
            :class="{ 'dark:hidden': branding.logoDarkUrl }"
        />
        <img
            v-if="branding.logoDarkUrl"
            :src="branding.logoDarkUrl"
            :alt="branding.appName"
            class="hidden size-8 object-contain dark:block"
        />
    </div>
    <div
        v-else
        class="flex aspect-square size-8 items-center justify-center rounded-md bg-sidebar-primary text-sidebar-primary-foreground"
    >
        <AppLogoIcon class="size-5 fill-current" />
    </div>
    <div class="ml-1 grid flex-1 text-left text-sm">
        <span class="mb-0.5 truncate leading-tight font-semibold">{{
            branding.appName
        }}</span>
    </div>
</template>
