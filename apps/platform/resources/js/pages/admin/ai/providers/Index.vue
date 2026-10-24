<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Brain, Pencil, Plug, Plus, Star, Trash2 } from '@lucide/vue';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import PageHeader from '@/components/PageHeader.vue';
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
import { formatMoney, formatNumber, t } from '@/lib/i18n';
import {
    create,
    destroy,
    edit,
    index,
    test,
} from '@/routes/admin/ai/providers';
import type { AiProviderData } from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Providers & models', href: index() }],
    },
});

defineProps<{
    providers: AiProviderData[];
}>();

const runTest = (provider: AiProviderData) =>
    router.post(test(provider.id).url, {}, { preserveScroll: true });

const remove = (provider: AiProviderData) =>
    router.delete(destroy(provider.id).url, { preserveScroll: true });
</script>

<template>
    <Head :title="t('Providers & models')" />

    <div class="flex flex-col gap-6 p-4 md:p-6">
        <PageHeader
            :title="t('Providers & models')"
            :description="
                t(
                    'Connect OpenAI, Anthropic, DeepSeek, OpenRouter, Amazon Bedrock or any OpenAI compatible API',
                )
            "
        >
            <Button as-child>
                <Link :href="create()">
                    <Plus class="size-4" />
                    {{ t('Add provider') }}
                </Link>
            </Button>
        </PageHeader>

        <div
            v-if="!providers.length"
            class="flex flex-col items-center gap-3 rounded-lg border border-dashed p-12 text-center"
        >
            <Brain class="size-10 text-muted-foreground" />
            <p class="font-medium">{{ t('No providers configured yet') }}</p>
            <p class="max-w-md text-sm text-muted-foreground">
                {{
                    t(
                        'Add your first provider with its API key to start using the AI chat and the AI API.',
                    )
                }}
            </p>
            <Button as-child>
                <Link :href="create()">{{ t('Add provider') }}</Link>
            </Button>
        </div>

        <div v-else class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
            <Card
                v-for="provider in providers"
                :key="provider.id"
                class="justify-between"
            >
                <CardHeader>
                    <CardTitle class="flex flex-wrap items-center gap-2">
                        {{ provider.label }}
                        <Badge v-if="provider.is_default" class="gap-1">
                            <Star class="size-3" />
                            {{ t('Default') }}
                        </Badge>
                        <Badge v-if="!provider.is_active" variant="outline">{{
                            t('Inactive')
                        }}</Badge>
                    </CardTitle>
                    <CardDescription class="font-mono text-xs">
                        {{ provider.driver_label }} · {{ provider.name }}
                    </CardDescription>
                </CardHeader>
                <CardContent class="grid gap-1 text-sm">
                    <div class="flex justify-between">
                        <span class="text-muted-foreground">{{
                            t('Default model')
                        }}</span>
                        <span class="truncate font-mono text-xs">{{
                            provider.default_model ?? '—'
                        }}</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-muted-foreground">{{
                            t('Priced models')
                        }}</span>
                        <span>{{ provider.models_count ?? 0 }}</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-muted-foreground">{{
                            t('This month')
                        }}</span>
                        <span
                            >{{ formatMoney(provider.month_cost) }} ·
                            {{ formatNumber(provider.month_calls) }}
                            {{ t('calls') }}</span
                        >
                    </div>
                </CardContent>
                <CardFooter class="justify-end gap-1">
                    <Button
                        variant="ghost"
                        size="sm"
                        @click="runTest(provider)"
                    >
                        <Plug class="size-4" />
                        {{ t('Test') }}
                    </Button>
                    <Button variant="ghost" size="sm" as-child>
                        <Link :href="edit(provider.id)">
                            <Pencil class="size-4" />
                            {{ t('Edit') }}
                        </Link>
                    </Button>
                    <ConfirmDialog
                        :title="t('Delete provider')"
                        :description="
                            t(
                                'Its models and prices will be deleted. Usage history is kept.',
                            )
                        "
                        :confirm-text="t('Delete')"
                        @confirm="remove(provider)"
                    >
                        <Button
                            variant="ghost"
                            size="icon"
                            class="text-destructive"
                            :aria-label="t('Delete')"
                        >
                            <Trash2 class="size-4" />
                        </Button>
                    </ConfirmDialog>
                </CardFooter>
            </Card>
        </div>
    </div>
</template>
