<script setup lang="ts" generic="T">
import { ref, watch } from 'vue';
import type { CarouselApi } from '@starter/ui/components/carousel';
import {
    Carousel,
    CarouselContent,
    CarouselItem,
    CarouselNext,
    CarouselPrevious,
} from '@starter/ui/components/carousel';
import { cn } from '@/lib/utils';
import { t } from '@/lib/i18n';

/**
 * Carousel of any items (images, cards, testimonials) with arrows, dots
 * and keyboard support. `itemClass` sets how many fit per breakpoint,
 * e.g. "basis-full sm:basis-1/2 lg:basis-1/3".
 *
 * <ContentCarousel :items="products" item-class="sm:basis-1/2 lg:basis-1/3" v-slot="{ item }">
 *     <ProductCard :product="item" />
 * </ContentCarousel>
 */
const props = withDefaults(
    defineProps<{
        items: T[];
        itemClass?: string;
        /** Start again after the last item. */
        loop?: boolean;
        dots?: boolean;
        arrows?: boolean;
        /** Advance every n milliseconds (0 = off; pauses on hover). */
        autoplay?: number;
        label?: string;
    }>(),
    {
        itemClass: 'basis-full',
        loop: false,
        dots: true,
        arrows: true,
        autoplay: 0,
        label: '',
    },
);

defineSlots<{
    default: (props: { item: T; index: number }) => unknown;
}>();

const api = ref<CarouselApi>();
const selected = ref(0);
const snaps = ref<number[]>([]);
const paused = ref(false);

function sync() {
    if (!api.value) {
        return;
    }

    snaps.value = api.value.scrollSnapList();
    selected.value = api.value.selectedScrollSnap();
}

function init(value: CarouselApi) {
    api.value = value;
    sync();
    value?.on('select', sync);
    value?.on('reInit', sync);
}

let timer: ReturnType<typeof setInterval> | undefined;

watch(
    [() => props.autoplay, api, paused],
    ([interval, carousel, isPaused], _old, onCleanup) => {
        clearInterval(timer);

        if (!interval || !carousel || isPaused) {
            return;
        }

        timer = setInterval(() => {
            if (carousel.canScrollNext()) {
                carousel.scrollNext();
            } else {
                carousel.scrollTo(0);
            }
        }, interval);

        onCleanup(() => clearInterval(timer));
    },
);
</script>

<template>
    <div
        class="grid gap-3"
        @pointerenter="paused = true"
        @pointerleave="paused = false"
        @focusin="paused = true"
        @focusout="paused = false"
    >
        <Carousel
            :opts="{ loop, align: 'start' }"
            :class="arrows && 'mx-10'"
            :aria-label="label || undefined"
            @init-api="init"
        >
            <CarouselContent>
                <CarouselItem
                    v-for="(item, index) in items"
                    :key="index"
                    :class="cn(itemClass)"
                    :aria-label="
                        t(':current of :total', {
                            current: index + 1,
                            total: items.length,
                        })
                    "
                >
                    <slot :item="item" :index="index" />
                </CarouselItem>
            </CarouselContent>
            <template v-if="arrows">
                <CarouselPrevious :aria-label="t('Previous')" />
                <CarouselNext :aria-label="t('Next')" />
            </template>
        </Carousel>

        <div
            v-if="dots && snaps.length > 1"
            class="flex justify-center gap-1.5"
        >
            <button
                v-for="(_, index) in snaps"
                :key="index"
                type="button"
                class="h-2 rounded-full transition-all focus-visible:ring-2 focus-visible:ring-ring/50 focus-visible:outline-none"
                :class="
                    index === selected
                        ? 'w-6 bg-primary'
                        : 'w-2 bg-muted-foreground/30 hover:bg-muted-foreground/60'
                "
                :aria-label="t('Go to slide :number', { number: index + 1 })"
                :aria-current="index === selected ? 'true' : undefined"
                @click="api?.scrollTo(index)"
            />
        </div>
    </div>
</template>
