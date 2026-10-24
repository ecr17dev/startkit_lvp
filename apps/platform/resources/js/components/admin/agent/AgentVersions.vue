<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { History, RotateCcw } from '@lucide/vue';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import { Badge } from '@starter/ui/components/badge';
import { Button } from '@starter/ui/components/button';
import {
    Card,
    CardContent,
    CardHeader,
    CardTitle,
} from '@starter/ui/components/card';
import { formatDate, t } from '@/lib/i18n';
import agentRoutes from '@/routes/admin/ai/agents';
import type { AgentVersionRow } from '@/types';

const props = defineProps<{ agentId: number; versions: AgentVersionRow[] }>();

const statusLabels: Record<string, string> = {
    draft: 'Draft',
    published: 'Published',
    archived: 'Archived',
};

const groupLabels: Record<string, string> = {
    identity: 'Identity',
    instructions: 'Instructions',
    execution: 'Model',
    responses: 'Responses',
    context: 'Context',
    multimedia: 'Attachments',
    microphone: 'Microphone',
    tools: 'Tools',
    rag: 'Knowledge (RAG)',
    access: 'Access',
    limits: 'Limits',
};

const restore = (version: AgentVersionRow) =>
    router.post(
        agentRoutes.versions.restore({
            agent: props.agentId,
            version: version.id,
        }).url,
        {},
        { preserveScroll: true },
    );
</script>

<template>
    <Card>
        <CardHeader>
            <CardTitle class="flex items-center gap-2">
                <History class="size-4" />
                {{ t('History') }}
            </CardTitle>
        </CardHeader>
        <CardContent>
            <ol class="grid gap-4">
                <li
                    v-for="version in versions"
                    :key="version.id"
                    class="grid gap-1 border-l-2 pl-3"
                    :class="
                        version.status === 'published'
                            ? 'border-primary'
                            : 'border-muted'
                    "
                >
                    <div class="flex items-center justify-between gap-2">
                        <span
                            class="flex items-center gap-2 text-sm font-medium"
                        >
                            v{{ version.number }}
                            <Badge
                                :variant="
                                    version.status === 'published'
                                        ? 'default'
                                        : version.status === 'draft'
                                          ? 'secondary'
                                          : 'outline'
                                "
                                >{{ t(statusLabels[version.status]) }}</Badge
                            >
                        </span>
                        <ConfirmDialog
                            v-if="version.status === 'archived'"
                            :title="t('Restore this version?')"
                            :description="
                                t(
                                    'Its settings are published again as a new version. The current published version is archived.',
                                )
                            "
                            :confirm-text="t('Restore')"
                            :destructive="false"
                            @confirm="restore(version)"
                        >
                            <Button variant="ghost" size="sm">
                                <RotateCcw class="size-3.5" />
                                {{ t('Restore') }}
                            </Button>
                        </ConfirmDialog>
                    </div>
                    <p v-if="version.notes" class="text-sm">
                        {{ version.notes }}
                    </p>
                    <p
                        v-if="version.changes.length"
                        class="text-xs text-muted-foreground"
                    >
                        {{ t('Changed') }}:
                        {{
                            version.changes
                                .map((group) => t(groupLabels[group] ?? group))
                                .join(', ')
                        }}
                    </p>
                    <p class="text-xs text-muted-foreground">
                        {{
                            version.published_at
                                ? `${t('Published')} ${formatDate(version.published_at)}${version.published_by ? ` · ${version.published_by}` : ''}`
                                : `${t('Created')} ${formatDate(version.created_at)}${version.created_by ? ` · ${version.created_by}` : ''}`
                        }}
                    </p>
                </li>
            </ol>
        </CardContent>
    </Card>
</template>
