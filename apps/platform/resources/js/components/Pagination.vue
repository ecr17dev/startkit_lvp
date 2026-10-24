<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ChevronLeft, ChevronRight } from '@lucide/vue';
import { computed } from 'vue';
import { Button } from '@starter/ui/components/button';
import { t } from '@/lib/i18n';
import type { PaginationMeta } from '@/types';

const props = defineProps<{
    meta: Pick<PaginationMeta, 'links' | 'from' | 'to' | 'total' | 'last_page'>;
}>();

const pages = computed(() => props.meta.links.slice(1, -1));
const previous = computed(() => props.meta.links[0]);
const next = computed(() => props.meta.links[props.meta.links.length - 1]);
</script>

<template>
    <div
        v-if="meta.total > 0"
        class="flex flex-col items-center justify-between gap-3 sm:flex-row"
    >
        <p class="text-sm text-muted-foreground">
            {{
                t('Showing :from to :to of :total results', {
                    from: meta.from ?? 0,
                    to: meta.to ?? 0,
                    total: meta.total,
                })
            }}
        </p>

        <nav
            v-if="meta.last_page > 1"
            class="flex items-center gap-1"
            :aria-label="t('Pagination')"
        >
            <Button
                variant="outline"
                size="icon"
                :disabled="!previous?.url"
                :as-child="!!previous?.url"
                :aria-label="t('Previous')"
            >
                <Link v-if="previous?.url" :href="previous.url" preserve-scroll>
                    <ChevronLeft class="size-4" />
                </Link>
                <ChevronLeft v-else class="size-4" />
            </Button>

            <template v-for="(link, index) in pages" :key="index">
                <Button
                    v-if="link.url"
                    :variant="link.active ? 'default' : 'ghost'"
                    size="icon"
                    as-child
                >
                    <Link :href="link.url" preserve-scroll>{{
                        link.label
                    }}</Link>
                </Button>
                <span v-else class="px-2 text-sm text-muted-foreground">…</span>
            </template>

            <Button
                variant="outline"
                size="icon"
                :disabled="!next?.url"
                :as-child="!!next?.url"
                :aria-label="t('Next')"
            >
                <Link v-if="next?.url" :href="next.url" preserve-scroll>
                    <ChevronRight class="size-4" />
                </Link>
                <ChevronRight v-else class="size-4" />
            </Button>
        </nav>
    </div>
</template>
