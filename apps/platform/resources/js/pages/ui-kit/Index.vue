<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ArrowRight } from '@lucide/vue';
import { catalogSections } from '@/components/ui-kit/sections';
import Example from '@/components/ui-kit/Example.vue';
import {
    Card,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@starter/ui/components/card';
import { t } from '@/lib/i18n';
import { index, show } from '@/routes/ui-kit';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'UI kit', href: index() }],
    },
});

const usage = `
<script setup lang="ts">
// Composites of the kit (forms, tables, charts, modals...)
import { DataTable, FormField, KpiCard, Modal } from '@/components/kit';
// Primitives of shadcn-vue (new-york-v4) in the shared package
import { Button } from '@starter/ui/components/button';
import { Input } from '@starter/ui/components/input';
// Icons
import { Plus } from '@lucide/vue';
// Texts: write in English, translate in lang/es.json
import { t } from '@/lib/i18n';
<\u002Fscript>
`;

const add = `
# Add a component of the shadcn-vue registry to @starter/ui
node packages/js/ui/scripts/add.mjs sonner
`;
</script>

<template>
    <Head :title="t('UI kit')" />

    <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
        <Link
            v-for="section in catalogSections"
            :key="section.key"
            :href="show(section.key)"
            class="group rounded-xl focus-visible:ring-2 focus-visible:ring-ring/50 focus-visible:outline-none"
        >
            <Card
                class="h-full gap-2 transition-colors group-hover:bg-muted/50"
            >
                <CardHeader>
                    <div class="flex items-center justify-between">
                        <div
                            class="flex size-9 items-center justify-center rounded-lg bg-muted text-foreground"
                        >
                            <component :is="section.icon" class="size-5" />
                        </div>
                        <ArrowRight
                            class="size-4 text-muted-foreground transition-transform group-hover:translate-x-0.5"
                        />
                    </div>
                    <CardTitle class="pt-2">{{ t(section.title) }}</CardTitle>
                    <CardDescription>{{
                        t(section.description)
                    }}</CardDescription>
                </CardHeader>
            </Card>
        </Link>
    </div>

    <Example
        :title="t('How to use it')"
        :description="
            t(
                'Kit composites live in resources/js/components/kit; primitives in packages/js/ui. The guide is docs/ui-kit.md.',
            )
        "
        :code="usage"
    >
        <ul class="grid gap-2 text-sm">
            <li>
                <span class="font-medium">@/components/kit</span> —
                {{
                    t(
                        'DataTable, FormField, WizardForm, charts, Modal, StatusAlert, ActionsMenu, Icon and more.',
                    )
                }}
            </li>
            <li>
                <span class="font-medium">@starter/ui/components/*</span> —
                {{
                    t(
                        'Button, Input, Select, Dialog, Drawer, Tabs, Carousel, Calendar, Command and the rest of shadcn-vue.',
                    )
                }}
            </li>
            <li>
                <span class="font-medium">@lucide/vue</span> —
                {{ t('Icons (see the Icons section).') }}
            </li>
        </ul>
    </Example>

    <Example
        :title="t('Add more primitives')"
        :description="
            t(
                'The script downloads the component from the shadcn-vue registry (new-york-v4) and rewrites its imports.',
            )
        "
        :code="add"
    >
        <p class="text-sm text-muted-foreground">
            {{
                t(
                    'If it reports missing npm dependencies, add them with pnpm --filter @starter/ui add.',
                )
            }}
        </p>
    </Example>
</template>
