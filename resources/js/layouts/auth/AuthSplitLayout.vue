<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import BrandMark from '@/components/BrandMark.vue';
import LanguageSwitcher from '@/components/LanguageSwitcher.vue';
import { Toaster } from '@/components/ui/sonner';
import { home } from '@/routes';

const page = usePage();
const branding = computed(() => page.props.branding);

defineProps<{
    title?: string;
    description?: string;
}>();
</script>

<template>
    <div
        class="relative grid h-dvh flex-col items-center justify-center px-8 sm:px-0 lg:max-w-none lg:grid-cols-2 lg:px-0"
    >
        <div
            class="relative hidden h-full flex-col bg-primary p-10 text-primary-foreground lg:flex"
        >
            <Link
                :href="home()"
                class="relative z-20 flex items-center gap-2 text-lg font-medium"
            >
                <span
                    class="flex size-9 items-center justify-center rounded-md bg-background p-1 text-foreground"
                >
                    <BrandMark size="size-7" />
                </span>
                {{ branding.appName }}
            </Link>
            <div v-if="branding.tagline" class="relative z-20 mt-auto">
                <p class="text-2xl leading-snug font-semibold">
                    {{ branding.tagline }}
                </p>
            </div>
        </div>
        <div class="relative lg:p-8">
            <div class="absolute -top-16 right-0 lg:top-0 lg:right-4">
                <LanguageSwitcher />
            </div>
            <div
                class="mx-auto flex w-full flex-col justify-center space-y-6 sm:w-[350px]"
            >
                <div class="flex flex-col space-y-2 text-center">
                    <h1 class="text-xl font-medium tracking-tight" v-if="title">
                        {{ title }}
                    </h1>
                    <p class="text-sm text-muted-foreground" v-if="description">
                        {{ description }}
                    </p>
                </div>
                <slot />
            </div>
        </div>
        <Toaster />
    </div>
</template>
