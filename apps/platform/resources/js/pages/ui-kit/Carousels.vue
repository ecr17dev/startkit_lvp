<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { Bot, ChartLine, Rocket, ShieldCheck, Star } from '@lucide/vue';
import ContentCarousel from '@/components/kit/ContentCarousel.vue';
import Example from '@/components/ui-kit/Example.vue';
import { Badge } from '@starter/ui/components/badge';
import { Button } from '@starter/ui/components/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardFooter,
    CardHeader,
    CardTitle,
} from '@starter/ui/components/card';
import { formatMoney, t } from '@/lib/i18n';
import { index, show } from '@/routes/ui-kit';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'UI kit', href: index() },
            { title: 'Carousels', href: show('carousels') },
        ],
    },
});

const slides = [
    {
        icon: Rocket,
        title: t('Launch faster'),
        text: t('Modules, API and panel ready from day one.'),
        class: 'from-chart-1/25 to-chart-1/5',
    },
    {
        icon: Bot,
        title: t('AI with control'),
        text: t('Agents with permissions, costs and approvals.'),
        class: 'from-chart-7/25 to-chart-7/5',
    },
    {
        icon: ShieldCheck,
        title: t('Secure by default'),
        text: t('Codes by email, roles, audit and sessions.'),
        class: 'from-chart-3/25 to-chart-3/5',
    },
    {
        icon: ChartLine,
        title: t('Measure everything'),
        text: t('Dashboards with accessible charts.'),
        class: 'from-chart-2/25 to-chart-2/5',
    },
];

const products = [
    { name: 'Starter', price: 49, rating: 4.6, tag: t('Popular') },
    { name: 'Team', price: 290, rating: 4.8, tag: t('Recommended') },
    { name: 'Enterprise', price: 1490, rating: 4.9, tag: '' },
    { name: 'Add-on: RAG', price: 120, rating: 4.4, tag: t('New') },
    { name: 'Add-on: API', price: 80, rating: 4.5, tag: '' },
    { name: 'Support 24/7', price: 390, rating: 4.7, tag: '' },
];

const code = `
<ContentCarousel :items="products" item-class="basis-full sm:basis-1/2 lg:basis-1/3"
    :label="t('Plans')" v-slot="{ item }">
    <Card class="h-full">...{{ item.name }}...</Card>
</ContentCarousel>

<!-- Slides that advance alone every 5 s (pause on hover or focus) -->
<ContentCarousel :items="slides" loop :autoplay="5000" v-slot="{ item }">...</ContentCarousel>
`;
</script>

<template>
    <Head :title="t('Carousels')" />

    <Example
        :title="t('Slides')"
        :description="
            t(
                'One item per view, loop and autoplay that stops on hover or focus. Arrow keys work when it has focus.',
            )
        "
        :code="code"
    >
        <ContentCarousel
            v-slot="{ item }"
            :items="slides"
            :label="t('Highlights')"
            :autoplay="5000"
            loop
        >
            <div
                class="flex min-h-48 flex-col justify-end gap-2 rounded-xl border bg-gradient-to-br p-6 md:min-h-56 md:p-8"
                :class="item.class"
            >
                <component :is="item.icon" class="size-8" />
                <p class="text-xl font-semibold tracking-tight md:text-2xl">
                    {{ item.title }}
                </p>
                <p class="max-w-md text-sm text-muted-foreground">
                    {{ item.text }}
                </p>
            </div>
        </ContentCarousel>
    </Example>

    <Example
        :title="t('Cards per breakpoint')"
        :description="
            t(
                'item-class decides how many fit: one on phones, two on tablets, three on desktop.',
            )
        "
    >
        <ContentCarousel
            v-slot="{ item }"
            :items="products"
            :label="t('Plans')"
            item-class="basis-full sm:basis-1/2 lg:basis-1/3"
        >
            <Card class="h-full gap-3">
                <CardHeader>
                    <div class="flex items-center justify-between gap-2">
                        <CardTitle>{{ item.name }}</CardTitle>
                        <Badge v-if="item.tag" variant="secondary">{{
                            item.tag
                        }}</Badge>
                    </div>
                    <CardDescription class="flex items-center gap-1">
                        <Star class="size-3.5 fill-current" />
                        {{ item.rating }}
                    </CardDescription>
                </CardHeader>
                <CardContent>
                    <p class="text-2xl font-semibold">
                        {{ formatMoney(item.price) }}
                        <span class="text-sm font-normal text-muted-foreground">
                            / {{ t('month') }}
                        </span>
                    </p>
                </CardContent>
                <CardFooter class="mt-auto">
                    <Button variant="outline" class="w-full">{{
                        t('Choose')
                    }}</Button>
                </CardFooter>
            </Card>
        </ContentCarousel>
    </Example>
</template>
