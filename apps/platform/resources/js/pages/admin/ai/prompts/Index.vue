<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Bot, Pencil, Plus, Star, Trash2 } from '@lucide/vue';
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
import { t } from '@/lib/i18n';
import { create, destroy, edit, index } from '@/routes/admin/ai/prompts';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'System prompts', href: index() }],
    },
});

type PromptItem = {
    id: number;
    name: string;
    slug: string;
    description: string | null;
    model: string | null;
    provider: string | null;
    excerpt: string;
    is_default: boolean;
    is_active: boolean;
};

defineProps<{ prompts: PromptItem[] }>();

const remove = (prompt: PromptItem) =>
    router.delete(destroy(prompt.id).url, { preserveScroll: true });
</script>

<template>
    <Head :title="t('System prompts')" />

    <div class="flex flex-col gap-6 p-4 md:p-6">
        <PageHeader
            :title="t('System prompts')"
            :description="
                t('Instructions that define the behaviour of the assistant')
            "
        >
            <Button as-child>
                <Link :href="create()">
                    <Plus class="size-4" />
                    {{ t('New prompt') }}
                </Link>
            </Button>
        </PageHeader>

        <div
            v-if="!prompts.length"
            class="flex flex-col items-center gap-3 rounded-lg border border-dashed p-12"
        >
            <Bot class="size-10 text-muted-foreground" />
            <p class="font-medium">{{ t('No prompts yet') }}</p>
        </div>

        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
            <Card
                v-for="prompt in prompts"
                :key="prompt.id"
                class="justify-between"
            >
                <CardHeader>
                    <CardTitle class="flex flex-wrap items-center gap-2">
                        {{ prompt.name }}
                        <Badge v-if="prompt.is_default" class="gap-1">
                            <Star class="size-3" />
                            {{ t('Default') }}
                        </Badge>
                        <Badge v-if="!prompt.is_active" variant="outline">{{
                            t('Inactive')
                        }}</Badge>
                    </CardTitle>
                    <CardDescription>{{ prompt.description }}</CardDescription>
                </CardHeader>
                <CardContent class="grid gap-2">
                    <p
                        class="line-clamp-3 rounded-md bg-muted p-2 font-mono text-xs whitespace-pre-line"
                    >
                        {{ prompt.excerpt }}
                    </p>
                    <p class="font-mono text-xs text-muted-foreground">
                        {{ prompt.slug }} ·
                        {{ prompt.provider ?? t('Default provider') }}
                        <template v-if="prompt.model">
                            · {{ prompt.model }}</template
                        >
                    </p>
                </CardContent>
                <CardFooter class="justify-end gap-1">
                    <Button variant="ghost" size="sm" as-child>
                        <Link :href="edit(prompt.id)">
                            <Pencil class="size-4" />
                            {{ t('Edit') }}
                        </Link>
                    </Button>
                    <ConfirmDialog
                        :title="t('Delete prompt')"
                        :description="t('Usage history is kept.')"
                        :confirm-text="t('Delete')"
                        @confirm="remove(prompt)"
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
