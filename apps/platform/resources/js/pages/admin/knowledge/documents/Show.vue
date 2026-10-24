<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { Download, ExternalLink, FileText, Trash2 } from '@lucide/vue';
import { ref } from 'vue';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import JobsTable from '@/components/knowledge/JobsTable.vue';
import PageHeader from '@/components/PageHeader.vue';
import { Badge } from '@starter/ui/components/badge';
import { Button } from '@starter/ui/components/button';
import {
    Card,
    CardContent,
    CardHeader,
    CardTitle,
} from '@starter/ui/components/card';
import {
    Tabs,
    TabsContent,
    TabsList,
    TabsTrigger,
} from '@starter/ui/components/tabs';
import { usePermissions } from '@/composables/usePermissions';
import { formatDate, formatNumber, t } from '@/lib/i18n';
import {
    documentStatusLabels,
    documentStatusVariant,
    formatBytes,
    principalLabel,
} from '@/lib/knowledge';
import knowledge from '@/routes/admin/knowledge';
import collectionRoutes from '@/routes/admin/knowledge/collections';
import documentRoutes from '@/routes/admin/knowledge/documents';
import type {
    ChunkRow,
    DocumentDetail,
    DocumentVersionDetail,
    RagJobSummary,
} from '@/types';

const props = defineProps<{
    document: DocumentDetail;
    version: DocumentVersionDetail | null;
    versions: {
        id: number;
        number: number;
        content_hash: string;
        parser: string;
        size: number;
        created_at: string | null;
    }[];
    chunks: ChunkRow[];
    profile: {
        name: string;
        model: string;
        dimensions: number;
        version: number;
    } | null;
    jobs: RagJobSummary[];
}>();

defineOptions({
    layout: (props: { document: DocumentDetail }) => ({
        breadcrumbs: [
            { title: 'Knowledge', href: knowledge.index() },
            {
                title: props.document.collection.name,
                href: collectionRoutes.show(props.document.collection.id),
            },
            {
                title: props.document.title ?? props.document.external_id,
                href: documentRoutes.show(props.document.id),
            },
        ],
    }),
});

const { can } = usePermissions();
const tab = ref('chunks');

const destroy = () =>
    router.delete(documentRoutes.destroy(props.document.id).url, {
        onSuccess: () =>
            router.visit(
                collectionRoutes.show(props.document.collection.id).url,
            ),
    });

const location = (chunk: ChunkRow) =>
    [
        chunk.metadata.section,
        chunk.metadata.page_start
            ? chunk.metadata.page_start === chunk.metadata.page_end
                ? t('page :n', { n: chunk.metadata.page_start })
                : t('pages :from-:to', {
                      from: chunk.metadata.page_start,
                      to: chunk.metadata.page_end ?? '',
                  })
            : null,
        `bytes ${chunk.metadata.start}–${chunk.metadata.end}`,
    ]
        .filter(Boolean)
        .join(' · ');
</script>

<template>
    <Head :title="document.title ?? t('Document')" />

    <div class="flex flex-col gap-6 p-4 md:p-6">
        <PageHeader
            :title="document.title ?? document.external_id"
            :description="document.external_id"
        >
            <template #icon><FileText class="size-5" /></template>
            <Button v-if="document.uri" variant="outline" as-child>
                <a
                    :href="document.uri"
                    target="_blank"
                    rel="noopener noreferrer"
                    ><ExternalLink class="size-4" /> {{ t('Open origin') }}</a
                >
            </Button>
            <Button v-if="version?.has_original" variant="outline" as-child>
                <a :href="documentRoutes.original(document.id).url"
                    ><Download class="size-4" /> {{ t('Original') }}</a
                >
            </Button>
            <ConfirmDialog
                v-if="
                    can('rag.documents.delete') && document.status !== 'deleted'
                "
                :title="t('Delete document?')"
                :description="
                    t(
                        'It leaves the searches immediately; its original and versions are purged by a job.',
                    )
                "
                @confirm="destroy"
            >
                <Button variant="outline"
                    ><Trash2 class="size-4" /> {{ t('Delete') }}</Button
                >
            </ConfirmDialog>
        </PageHeader>

        <div class="grid gap-6 lg:grid-cols-3">
            <Card class="min-w-0 lg:col-span-1">
                <CardHeader
                    ><CardTitle>{{ t('Details') }}</CardTitle></CardHeader
                >
                <CardContent>
                    <dl class="grid gap-3 text-sm">
                        <div>
                            <dt class="text-xs text-muted-foreground">
                                {{ t('Status') }}
                            </dt>
                            <dd>
                                <Badge
                                    :variant="
                                        documentStatusVariant[document.status]
                                    "
                                    >{{
                                        t(
                                            documentStatusLabels[
                                                document.status
                                            ] ?? document.status,
                                        )
                                    }}</Badge
                                >
                                <p
                                    v-if="document.error"
                                    class="mt-1 text-xs text-destructive"
                                >
                                    {{ document.error }}
                                </p>
                            </dd>
                        </div>
                        <div>
                            <dt class="text-xs text-muted-foreground">
                                {{ t('Type') }}
                            </dt>
                            <dd>
                                {{ document.mime_type }} ·
                                {{ formatBytes(document.size) }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-xs text-muted-foreground">
                                {{ t('Source') }}
                            </dt>
                            <dd>{{ document.source }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs text-muted-foreground">
                                {{ t('Content hash') }}
                            </dt>
                            <dd class="font-mono text-xs break-all">
                                {{ document.content_hash ?? '—' }}
                            </dd>
                        </div>
                        <div v-if="version">
                            <dt class="text-xs text-muted-foreground">
                                {{ t('Version') }}
                            </dt>
                            <dd>
                                v{{ version.number }} · {{ version.parser }}
                                <Badge
                                    v-if="!version.is_current"
                                    variant="outline"
                                    >{{ t('pending indexing') }}</Badge
                                >
                            </dd>
                        </div>
                        <div>
                            <dt class="text-xs text-muted-foreground">
                                {{ t('Chunks / tokens') }}
                            </dt>
                            <dd>
                                {{ document.chunk_count }} /
                                {{ formatNumber(document.token_count) }}
                            </dd>
                        </div>
                        <div v-if="profile">
                            <dt class="text-xs text-muted-foreground">
                                {{ t('Embeddings') }}
                            </dt>
                            <dd>
                                {{ profile.model }} · {{ profile.dimensions }}d
                                · v{{ profile.version }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-xs text-muted-foreground">
                                {{ t('Access') }}
                            </dt>
                            <dd class="flex flex-wrap gap-1">
                                <Badge
                                    v-for="principal in document.effective_acl"
                                    :key="principal"
                                    variant="secondary"
                                    >{{ principalLabel(principal) }}</Badge
                                >
                            </dd>
                        </div>
                        <div>
                            <dt class="text-xs text-muted-foreground">
                                {{ t('Indexed') }}
                            </dt>
                            <dd>{{ formatDate(document.indexed_at) }}</dd>
                        </div>
                        <div v-if="document.etag || document.last_modified">
                            <dt class="text-xs text-muted-foreground">
                                ETag / Last-Modified
                            </dt>
                            <dd class="text-xs break-all">
                                {{ document.etag ?? '—' }} ·
                                {{ document.last_modified ?? '—' }}
                            </dd>
                        </div>
                        <div v-if="version?.warnings?.length">
                            <dt class="text-xs text-muted-foreground">
                                {{ t('Warnings') }}
                            </dt>
                            <dd class="text-xs text-amber-600">
                                {{ version.warnings.join(' · ') }}
                            </dd>
                        </div>
                    </dl>
                </CardContent>
            </Card>

            <div class="min-w-0 lg:col-span-2">
                <Tabs v-model="tab">
                    <TabsList>
                        <TabsTrigger value="chunks"
                            >{{ t('Chunks') }} ({{
                                chunks.length
                            }})</TabsTrigger
                        >
                        <TabsTrigger value="content">{{
                            t('Extracted content')
                        }}</TabsTrigger>
                        <TabsTrigger value="versions"
                            >{{ t('Versions') }} ({{
                                versions.length
                            }})</TabsTrigger
                        >
                        <TabsTrigger value="jobs">{{ t('Jobs') }}</TabsTrigger>
                    </TabsList>
                    <TabsContent value="chunks" class="grid gap-3">
                        <div
                            v-for="chunk in chunks"
                            :key="chunk.id"
                            class="rounded-md border p-3"
                            :data-test="`chunk-${chunk.ordinal}`"
                        >
                            <div
                                class="mb-1 flex flex-wrap items-center gap-2 text-xs text-muted-foreground"
                            >
                                <Badge variant="outline"
                                    >#{{ chunk.ordinal + 1 }}</Badge
                                >
                                <span>{{ chunk.token_count }} tokens</span>
                                <span>{{ location(chunk) }}</span>
                            </div>
                            <p class="text-sm whitespace-pre-wrap">
                                {{ chunk.content }}
                            </p>
                        </div>
                        <p
                            v-if="!chunks.length"
                            class="text-sm text-muted-foreground"
                        >
                            {{ t('No chunks in the active version.') }}
                        </p>
                    </TabsContent>
                    <TabsContent value="content">
                        <pre
                            v-if="version"
                            class="max-h-[70vh] overflow-auto rounded-md border bg-muted/40 p-3 text-xs whitespace-pre-wrap"
                            >{{ version.content }}</pre>
                        <p
                            v-if="version?.truncated"
                            class="mt-2 text-xs text-muted-foreground"
                        >
                            {{ t('Showing the first 20,000 characters.') }}
                        </p>
                        <p
                            v-if="version?.pages"
                            class="mt-2 text-xs text-muted-foreground"
                        >
                            {{ t(':n pages', { n: version.pages.length }) }}
                        </p>
                    </TabsContent>
                    <TabsContent value="versions">
                        <ul class="grid gap-2 text-sm">
                            <li
                                v-for="item in versions"
                                :key="item.id"
                                class="flex flex-wrap justify-between gap-2 rounded-md border p-2"
                            >
                                <span
                                    >v{{ item.number }} · {{ item.parser }} ·
                                    {{ formatBytes(item.size) }}</span
                                >
                                <span
                                    class="font-mono text-xs text-muted-foreground"
                                    >{{ item.content_hash.slice(0, 16) }}… ·
                                    {{ formatDate(item.created_at) }}</span
                                >
                            </li>
                        </ul>
                    </TabsContent>
                    <TabsContent value="jobs">
                        <JobsTable
                            :jobs="jobs"
                            :can-manage="can('rag.jobs.manage')"
                        />
                    </TabsContent>
                </Tabs>
            </div>
        </div>
    </div>
</template>
