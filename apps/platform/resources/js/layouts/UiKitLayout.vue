<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Blocks } from '@lucide/vue';
import { catalogSections } from '@/components/ui-kit/sections';
import { useCurrentUrl } from '@/composables/useCurrentUrl';
import { t } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { index, show } from '@/routes/ui-kit';

const { isCurrentUrl } = useCurrentUrl();
</script>

<template>
    <div class="flex min-w-0 flex-col gap-6 p-4 md:p-6">
        <header class="flex items-start gap-3">
            <div
                class="flex size-10 shrink-0 items-center justify-center rounded-lg bg-primary text-primary-foreground"
            >
                <Blocks class="size-5" />
            </div>
            <div class="space-y-1">
                <h1 class="text-xl font-semibold tracking-tight">
                    {{ t('UI kit') }}
                </h1>
                <p class="text-sm text-muted-foreground">
                    {{
                        t(
                            'Reusable components with live examples. Copy the code and adapt it.',
                        )
                    }}
                </p>
            </div>
        </header>

        <nav
            class="-mx-4 overflow-x-auto px-4 md:mx-0 md:px-0"
            :aria-label="t('UI kit sections')"
        >
            <ul class="flex w-max gap-1 border-b">
                <li>
                    <Link
                        :href="index()"
                        :class="
                            cn(
                                '-mb-px inline-flex items-center border-b-2 border-transparent px-3 py-2 text-sm whitespace-nowrap text-muted-foreground hover:text-foreground',
                                isCurrentUrl(index()) &&
                                    'border-primary font-medium text-foreground',
                            )
                        "
                    >
                        {{ t('Overview') }}
                    </Link>
                </li>
                <li v-for="section in catalogSections" :key="section.key">
                    <Link
                        :href="show(section.key)"
                        :class="
                            cn(
                                '-mb-px inline-flex items-center gap-1.5 border-b-2 border-transparent px-3 py-2 text-sm whitespace-nowrap text-muted-foreground hover:text-foreground',
                                isCurrentUrl(show(section.key)) &&
                                    'border-primary font-medium text-foreground',
                            )
                        "
                    >
                        <component :is="section.icon" class="size-4" />
                        {{ t(section.title) }}
                    </Link>
                </li>
            </ul>
        </nav>

        <div class="grid min-w-0 gap-8">
            <slot />
        </div>
    </div>
</template>
