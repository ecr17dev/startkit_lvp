<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import {
    ExternalLink,
    FilePlus2,
    Library,
    Pause,
    Pencil,
    Play,
    Plus,
    RefreshCw,
    Trash2,
} from '@lucide/vue';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import InputError from '@/components/InputError.vue';
import CollectionForm from '@/components/knowledge/CollectionForm.vue';
import type { CollectionFormData } from '@/components/knowledge/CollectionForm.vue';
import JobsTable from '@/components/knowledge/JobsTable.vue';
import SourceDialog from '@/components/knowledge/SourceDialog.vue';
import PageHeader from '@/components/PageHeader.vue';
import Pagination from '@/components/Pagination.vue';
import StatCard from '@/components/StatCard.vue';
import {
    Alert,
    AlertDescription,
    AlertTitle,
} from '@starter/ui/components/alert';
import { Badge } from '@starter/ui/components/badge';
import { Button } from '@starter/ui/components/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@starter/ui/components/dialog';
import { Input } from '@starter/ui/components/input';
import { Label } from '@starter/ui/components/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@starter/ui/components/select';
import {
    Table,
    TableBody,
    TableCell,
    TableEmpty,
    TableHead,
    TableHeader,
    TableRow,
} from '@starter/ui/components/table';
import {
    Tabs,
    TabsContent,
    TabsList,
    TabsTrigger,
} from '@starter/ui/components/tabs';
import { usePermissions } from '@/composables/usePermissions';
import { formatCost } from '@/lib/ai';
import { formatDate, formatNumber, t } from '@/lib/i18n';
import {
    documentStatusLabels,
    documentStatusVariant,
    formatBytes,
    isOpenJob,
    scheduleLabels,
    sourceTypeLabels,
    versionStatusLabels,
    versionStatusVariant,
} from '@/lib/knowledge';
import knowledge from '@/routes/admin/knowledge';
import collectionRoutes from '@/routes/admin/knowledge/collections';
import documentRoutes from '@/routes/admin/knowledge/documents';
import sourceRoutes from '@/routes/admin/knowledge/sources';
import type {
    CollectionSummary,
    EmbeddingProfile,
    IndexVersionSummary,
    KnowledgeOptions,
    PaginatedDocuments,
    RagJobSummary,
    SourceSummary,
    RemoteDocuments,
} from '@/types';

const props = defineProps<{
    collection: CollectionSummary;
    sources: SourceSummary[];
    documents: PaginatedDocuments | null;
    filters: { status?: string; source?: number; search?: string };
    jobs: RagJobSummary[];
    versions: IndexVersionSummary[];
    profiles: EmbeddingProfile[];
    options: KnowledgeOptions;
    access: { readContent: boolean };
    /** Documents listed live by the retrieval API (remote collections). */
    remote: RemoteDocuments | null;
}>();

defineOptions({
    layout: (props: { collection: CollectionSummary }) => ({
        breadcrumbs: [
            { title: 'Knowledge', href: knowledge.index() },
            {
                title: props.collection.name,
                href: collectionRoutes.show(props.collection.id),
            },
        ],
    }),
});

const { can } = usePermissions();
const supports = (capability: string) =>
    props.collection.connection.capabilities.includes(capability);
const tab = ref(
    new URLSearchParams(window.location.search).get('tab') ??
        (props.collection.remote ? 'documents' : 'sources'),
);

const sourceOpen = ref(false);
const editingSource = ref<SourceSummary | null>(null);
const filesFor = ref<SourceSummary | null>(null);
const filesForm = useForm({ files: [] as File[] });

const openSource = (source: SourceSummary | null) => {
    editingSource.value = source;
    sourceOpen.value = true;
};

const submitFiles = () =>
    filesFor.value &&
    filesForm.post(sourceRoutes.files(filesFor.value.id).url, {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => {
            filesFor.value = null;
            filesForm.reset();
        },
    });

const fileErrors = computed(() =>
    Object.values(filesForm.errors as Record<string, string>),
);

const action = (
    method: 'post' | 'patch' | 'delete',
    url: string,
    data: Record<string, string> = {},
) => router[method](url, data, { preserveScroll: true });

const settingsForm = useForm<CollectionFormData>({
    name: props.collection.name,
    slug: props.collection.slug,
    description: props.collection.description ?? '',
    embedding_profile_id: props.collection.embedding_profile_id,
    external_id: props.collection.external_id,
    language: props.collection.language,
    chunking: { ...props.collection.chunking },
    search: { ...props.collection.search },
    acl: [...props.collection.acl],
    retention_days: props.collection.retention_days,
});

const saveSettings = () =>
    settingsForm.put(collectionRoutes.update(props.collection.id).url, {
        preserveScroll: true,
    });

const reindexOpen = ref(false);
const reindexForm = useForm({ max_cost: '' as string | number });
const reindex = () =>
    reindexForm
        .transform((data) => ({
            max_cost: data.max_cost === '' ? null : Number(data.max_cost),
        }))
        .post(collectionRoutes.reindex(props.collection.id).url, {
            preserveScroll: true,
            onSuccess: () => (reindexOpen.value = false),
        });

const deleteOpen = ref(false);
const deleteForm = useForm({ confirmation: '' });
const destroy = () =>
    deleteForm.delete(collectionRoutes.destroy(props.collection.id).url);

const search = ref(props.filters.search ?? '');
const statusFilter = ref(props.filters.status ?? 'all');
const applyFilters = () =>
    router.get(
        collectionRoutes.show(props.collection.id).url,
        {
            tab: 'documents',
            search: search.value || undefined,
            status:
                statusFilter.value === 'all' ? undefined : statusFilter.value,
        },
        {
            preserveState: true,
            preserveScroll: true,
            only: ['documents', 'filters'],
        },
    );
watch(statusFilter, applyFilters);

const totalChunks = computed(
    () => props.collection.active_version?.chunks ?? 0,
);
const totalCost = computed(() =>
    props.versions.some((version) => version.cost === null)
        ? null
        : props.versions.reduce(
              (sum, version) => sum + Number(version.cost ?? 0),
              0,
          ),
);

let timer: ReturnType<typeof setInterval> | undefined;

onMounted(() => {
    timer = setInterval(() => {
        if (
            props.jobs.some(
                (job) => isOpenJob(job.status) && job.status !== 'paused',
            )
        ) {
            router.reload({
                only: [
                    'jobs',
                    'sources',
                    'collection',
                    'documents',
                    'versions',
                ],
            });
        }
    }, 3000);
});
onBeforeUnmount(() => clearInterval(timer));
</script>

<template>
    <Head :title="collection.name" />

    <div class="flex flex-col gap-6 p-4 md:p-6">
        <PageHeader
            :title="collection.name"
            :description="collection.description ?? t('Knowledge collection')"
        >
            <template #icon><Library class="size-5" /></template>
            <Badge v-if="collection.connection.read_only" variant="outline">{{
                t('Read only · :connection', {
                    connection: collection.connection.label,
                })
            }}</Badge>
            <Button
                v-if="can('rag.jobs.manage') && supports('ingest')"
                variant="outline"
                :disabled="!!collection.building_version"
                data-test="collection-reindex"
                @click="reindexOpen = true"
            >
                <RefreshCw class="size-4" />
                {{ t('Reindex') }}
            </Button>
            <Button
                v-if="can('rag.collections.manage') && supports('delete')"
                variant="outline"
                @click="deleteOpen = true"
            >
                <Trash2 class="size-4" />
                {{ t('Delete') }}
            </Button>
        </PageHeader>

        <Alert v-if="collection.remote">
            <AlertTitle>{{
                t('Served by :connection', {
                    connection: collection.connection.label,
                })
            }}</AlertTitle>
            <AlertDescription>{{
                t(
                    'Remote collection :id: the retrieval API embeds, indexes and ranks. This installation applies the access list, sends the user principals with every search and keeps the traces.',
                    { id: collection.external_id ?? '' },
                )
            }}</AlertDescription>
        </Alert>

        <div
            v-if="!collection.remote"
            class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4"
        >
            <StatCard
                :title="t('Documents')"
                :value="formatNumber(collection.documents)"
                :hint="t(':n sources', { n: collection.sources })"
            />
            <StatCard
                :title="t('Chunks')"
                :value="formatNumber(totalChunks)"
                :hint="
                    collection.active_version
                        ? t('Active version v:n', {
                              n: collection.active_version.number,
                          })
                        : t('No version yet')
                "
            />
            <StatCard
                :title="t('Profile')"
                :value="collection.profile ?? '—'"
                :hint="
                    collection.needs_reindex
                        ? t('Changed settings: reindex to apply')
                        : t('In use')
                "
            />
            <StatCard
                :title="t('Indexing cost')"
                :value="
                    formatCost(totalCost === null ? null : String(totalCost))
                "
                :hint="t('Embeddings of every version')"
            />
        </div>

        <div
            v-if="collection.building_version"
            class="rounded-md border border-dashed p-3 text-sm"
        >
            {{
                t(
                    'Reindex in progress: version v:n is being built; v:active keeps answering until it is published.',
                    {
                        n: collection.building_version.number,
                        active: collection.active_version?.number ?? '—',
                    },
                )
            }}
        </div>

        <Tabs v-model="tab">
            <TabsList class="flex-wrap">
                <TabsTrigger v-if="!collection.remote" value="sources">{{
                    t('Sources')
                }}</TabsTrigger>
                <TabsTrigger
                    value="documents"
                    :disabled="!access.readContent"
                    >{{ t('Documents') }}</TabsTrigger
                >
                <template v-if="!collection.remote">
                    <TabsTrigger value="jobs">{{ t('Jobs') }}</TabsTrigger>
                    <TabsTrigger value="versions">{{
                        t('Index versions')
                    }}</TabsTrigger>
                </template>
                <TabsTrigger
                    v-if="can('rag.collections.manage')"
                    value="settings"
                    >{{ t('Settings') }}</TabsTrigger
                >
            </TabsList>

            <TabsContent value="sources" class="grid gap-3">
                <div class="flex justify-end">
                    <Button
                        v-if="can('rag.sources.manage') && supports('ingest')"
                        data-test="new-source"
                        @click="openSource(null)"
                    >
                        <Plus class="size-4" />
                        {{ t('New source') }}
                    </Button>
                </div>
                <div class="overflow-x-auto rounded-lg border">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>{{ t('Source') }}</TableHead>
                                <TableHead>{{ t('Status') }}</TableHead>
                                <TableHead>{{ t('Last sync') }}</TableHead>
                                <TableHead class="text-right">{{
                                    t('Documents')
                                }}</TableHead>
                                <TableHead class="text-right">{{
                                    t('Chunks')
                                }}</TableHead>
                                <TableHead class="text-right">{{
                                    t('Size')
                                }}</TableHead>
                                <TableHead class="text-right">{{
                                    t('Cost')
                                }}</TableHead>
                                <TableHead class="text-right" />
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            <TableRow
                                v-for="source in sources"
                                :key="source.id"
                                :data-test="`source-${source.id}`"
                            >
                                <TableCell class="align-top">
                                    <div class="font-medium">
                                        {{ source.name }}
                                    </div>
                                    <div class="text-xs text-muted-foreground">
                                        {{
                                            t(
                                                sourceTypeLabels[source.type] ??
                                                    source.type,
                                            )
                                        }}
                                        <template v-if="source.schedule">
                                            ·
                                            {{
                                                t(
                                                    scheduleLabels[
                                                        source.schedule
                                                    ] ?? source.schedule,
                                                )
                                            }}</template
                                        >
                                    </div>
                                    <div
                                        v-if="source.config.urls"
                                        class="max-w-64 truncate text-xs text-muted-foreground"
                                    >
                                        {{ source.config.urls.join(', ') }}
                                    </div>
                                    <div
                                        v-if="source.config.url"
                                        class="max-w-64 truncate text-xs text-muted-foreground"
                                    >
                                        {{ source.config.url }}
                                    </div>
                                </TableCell>
                                <TableCell class="align-top">
                                    <Badge
                                        :variant="
                                            source.status === 'active'
                                                ? 'secondary'
                                                : 'outline'
                                        "
                                        >{{
                                            t(
                                                source.status === 'active'
                                                    ? 'Active'
                                                    : source.status === 'paused'
                                                      ? 'Paused'
                                                      : 'Deleting',
                                            )
                                        }}</Badge
                                    >
                                    <Badge
                                        v-if="source.open_job"
                                        variant="secondary"
                                        class="ml-1"
                                        >{{ t('syncing') }}</Badge
                                    >
                                    <p
                                        v-if="source.last_error"
                                        class="mt-1 max-w-60 text-xs text-destructive"
                                    >
                                        {{ source.last_error }}
                                    </p>
                                    <p
                                        v-if="source.failed"
                                        class="mt-1 text-xs text-destructive"
                                    >
                                        {{
                                            t(':n failed documents', {
                                                n: source.failed,
                                            })
                                        }}
                                    </p>
                                </TableCell>
                                <TableCell
                                    class="align-top text-xs text-muted-foreground"
                                >
                                    {{ formatDate(source.last_sync_at) }}
                                    <div v-if="source.next_sync_at">
                                        {{
                                            t('Next: :date', {
                                                date: formatDate(
                                                    source.next_sync_at,
                                                ),
                                            })
                                        }}
                                    </div>
                                </TableCell>
                                <TableCell
                                    class="text-right align-top tabular-nums"
                                    >{{
                                        formatNumber(source.documents)
                                    }}</TableCell
                                >
                                <TableCell
                                    class="text-right align-top tabular-nums"
                                    >{{
                                        formatNumber(source.chunks)
                                    }}</TableCell
                                >
                                <TableCell
                                    class="text-right align-top tabular-nums"
                                    >{{ formatBytes(source.size) }}</TableCell
                                >
                                <TableCell
                                    class="text-right align-top tabular-nums"
                                    >{{ formatCost(source.cost) }}</TableCell
                                >
                                <TableCell class="text-right align-top">
                                    <div
                                        v-if="
                                            can('rag.sources.manage') &&
                                            supports('ingest') &&
                                            source.status !== 'deleting'
                                        "
                                        class="flex justify-end gap-1"
                                    >
                                        <Button
                                            size="icon"
                                            variant="ghost"
                                            :aria-label="t('Sync now')"
                                            :disabled="
                                                !!source.open_job ||
                                                source.status !== 'active'
                                            "
                                            data-test="source-sync"
                                            @click="
                                                action(
                                                    'post',
                                                    sourceRoutes.sync(source.id)
                                                        .url,
                                                )
                                            "
                                        >
                                            <RefreshCw class="size-4" />
                                        </Button>
                                        <Button
                                            v-if="source.type === 'upload'"
                                            size="icon"
                                            variant="ghost"
                                            :aria-label="t('Add files')"
                                            @click="filesFor = source"
                                        >
                                            <FilePlus2 class="size-4" />
                                        </Button>
                                        <Button
                                            size="icon"
                                            variant="ghost"
                                            :aria-label="
                                                source.status === 'active'
                                                    ? t('Pause')
                                                    : t('Resume')
                                            "
                                            @click="
                                                action(
                                                    'patch',
                                                    sourceRoutes.status(
                                                        source.id,
                                                    ).url,
                                                    {
                                                        status:
                                                            source.status ===
                                                            'active'
                                                                ? 'paused'
                                                                : 'active',
                                                    },
                                                )
                                            "
                                        >
                                            <Pause
                                                v-if="
                                                    source.status === 'active'
                                                "
                                                class="size-4"
                                            />
                                            <Play v-else class="size-4" />
                                        </Button>
                                        <Button
                                            size="icon"
                                            variant="ghost"
                                            :aria-label="t('Edit')"
                                            @click="openSource(source)"
                                        >
                                            <Pencil class="size-4" />
                                        </Button>
                                        <ConfirmDialog
                                            :title="t('Delete source?')"
                                            :description="
                                                t(
                                                    'Its documents leave the searches now; originals and vectors are purged by a job.',
                                                )
                                            "
                                            @confirm="
                                                action(
                                                    'delete',
                                                    sourceRoutes.destroy(
                                                        source.id,
                                                    ).url,
                                                )
                                            "
                                        >
                                            <Button
                                                size="icon"
                                                variant="ghost"
                                                :aria-label="t('Delete')"
                                            >
                                                <Trash2 class="size-4" />
                                            </Button>
                                        </ConfirmDialog>
                                    </div>
                                </TableCell>
                            </TableRow>
                            <TableEmpty v-if="!sources.length" :colspan="8">{{
                                t(
                                    'No sources yet. Add files, web pages, a sitemap or internal content.',
                                )
                            }}</TableEmpty>
                        </TableBody>
                    </Table>
                </div>
            </TabsContent>

            <TabsContent value="documents" class="grid gap-3">
                <template v-if="collection.remote">
                    <p
                        v-if="remote && !remote.available"
                        class="rounded-md border border-dashed p-6 text-center text-sm text-muted-foreground"
                    >
                        {{
                            t('The retrieval API does not list its documents.')
                        }}
                    </p>
                    <p
                        v-else-if="remote?.error"
                        class="text-sm text-destructive"
                    >
                        {{ remote.error }}
                    </p>
                    <div
                        v-else-if="remote"
                        class="overflow-x-auto rounded-lg border"
                        data-test="remote-documents"
                    >
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>{{ t('Document') }}</TableHead>
                                    <TableHead>{{ t('Updated') }}</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                <TableRow
                                    v-for="document in remote.documents"
                                    :key="document.id"
                                >
                                    <TableCell>
                                        <a
                                            v-if="document.uri"
                                            :href="document.uri"
                                            target="_blank"
                                            rel="noopener noreferrer"
                                            class="font-medium hover:underline"
                                            >{{ document.title }}</a
                                        >
                                        <span v-else class="font-medium">{{
                                            document.title
                                        }}</span>
                                        <p
                                            class="font-mono text-xs text-muted-foreground"
                                        >
                                            {{ document.id }}
                                        </p>
                                    </TableCell>
                                    <TableCell
                                        class="text-xs text-muted-foreground"
                                        >{{
                                            document.updated_at
                                                ? formatDate(
                                                      document.updated_at,
                                                  )
                                                : '—'
                                        }}</TableCell
                                    >
                                </TableRow>
                                <TableEmpty
                                    v-if="!remote.documents.length"
                                    :colspan="2"
                                    >{{ t('No documents.') }}</TableEmpty
                                >
                            </TableBody>
                        </Table>
                    </div>
                    <div
                        v-if="remote && (remote.page > 1 || remote.next_page)"
                        class="flex justify-end gap-2"
                    >
                        <Button
                            v-if="remote.page > 1"
                            variant="outline"
                            size="sm"
                            as-child
                        >
                            <Link
                                :href="
                                    collectionRoutes.show(collection.id, {
                                        query: {
                                            tab: 'documents',
                                            page: remote.page - 1,
                                        },
                                    })
                                "
                                >{{ t('Previous') }}</Link
                            >
                        </Button>
                        <Button
                            v-if="remote.next_page"
                            variant="outline"
                            size="sm"
                            as-child
                        >
                            <Link
                                :href="
                                    collectionRoutes.show(collection.id, {
                                        query: {
                                            tab: 'documents',
                                            page: remote.next_page,
                                        },
                                    })
                                "
                                >{{ t('Next') }}</Link
                            >
                        </Button>
                    </div>
                </template>
                <template v-else-if="documents">
                    <div class="flex flex-wrap gap-2">
                        <Input
                            v-model="search"
                            class="w-64"
                            :placeholder="t('Search by title or address')"
                            @keydown.enter="applyFilters"
                        />
                        <Select v-model="statusFilter">
                            <SelectTrigger class="w-48"
                                ><SelectValue
                            /></SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">{{
                                    t('All statuses')
                                }}</SelectItem>
                                <SelectItem
                                    v-for="(
                                        label, value
                                    ) in documentStatusLabels"
                                    :key="value"
                                    :value="value"
                                    >{{ t(label) }}</SelectItem
                                >
                            </SelectContent>
                        </Select>
                    </div>
                    <div class="overflow-x-auto rounded-lg border">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>{{ t('Document') }}</TableHead>
                                    <TableHead>{{ t('Status') }}</TableHead>
                                    <TableHead>{{ t('Source') }}</TableHead>
                                    <TableHead class="text-right">{{
                                        t('Chunks')
                                    }}</TableHead>
                                    <TableHead class="text-right"
                                        >Tokens</TableHead
                                    >
                                    <TableHead>{{ t('Indexed') }}</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                <TableRow
                                    v-for="document in documents.data"
                                    :key="document.id"
                                >
                                    <TableCell>
                                        <Link
                                            :href="
                                                documentRoutes.show(document.id)
                                            "
                                            class="font-medium hover:underline"
                                            :data-test="`document-${document.id}`"
                                            >{{
                                                document.title ??
                                                document.external_id
                                            }}</Link
                                        >
                                        <div
                                            class="flex max-w-80 items-center gap-1 truncate text-xs text-muted-foreground"
                                        >
                                            {{ document.mime_type }} ·
                                            {{ formatBytes(document.size) }}
                                            <a
                                                v-if="document.uri"
                                                :href="document.uri"
                                                target="_blank"
                                                rel="noopener noreferrer"
                                                class="inline-flex items-center gap-0.5 hover:underline"
                                                ><ExternalLink class="size-3"
                                            /></a>
                                        </div>
                                    </TableCell>
                                    <TableCell>
                                        <Badge
                                            :variant="
                                                documentStatusVariant[
                                                    document.status
                                                ]
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
                                            class="mt-1 max-w-64 text-xs text-muted-foreground"
                                        >
                                            {{ document.error }}
                                        </p>
                                    </TableCell>
                                    <TableCell class="text-sm">{{
                                        document.source
                                    }}</TableCell>
                                    <TableCell
                                        class="text-right tabular-nums"
                                        >{{ document.chunk_count }}</TableCell
                                    >
                                    <TableCell
                                        class="text-right tabular-nums"
                                        >{{
                                            formatNumber(document.token_count)
                                        }}</TableCell
                                    >
                                    <TableCell
                                        class="text-xs text-muted-foreground"
                                        >{{
                                            formatDate(document.indexed_at)
                                        }}</TableCell
                                    >
                                </TableRow>
                                <TableEmpty
                                    v-if="!documents.data.length"
                                    :colspan="6"
                                    >{{ t('No documents.') }}</TableEmpty
                                >
                            </TableBody>
                        </Table>
                    </div>
                    <Pagination :meta="documents.meta" />
                </template>
                <p v-else class="text-sm text-muted-foreground">
                    {{
                        t(
                            'Your access list does not include this collection content.',
                        )
                    }}
                </p>
            </TabsContent>

            <TabsContent value="jobs">
                <JobsTable :jobs="jobs" :can-manage="can('rag.jobs.manage')" />
            </TabsContent>

            <TabsContent value="versions">
                <div class="overflow-x-auto rounded-lg border">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>{{ t('Version') }}</TableHead>
                                <TableHead>{{ t('Status') }}</TableHead>
                                <TableHead>{{ t('Profile') }}</TableHead>
                                <TableHead>{{ t('Chunking') }}</TableHead>
                                <TableHead class="text-right">{{
                                    t('Documents')
                                }}</TableHead>
                                <TableHead class="text-right">{{
                                    t('Chunks')
                                }}</TableHead>
                                <TableHead class="text-right">{{
                                    t('Cost')
                                }}</TableHead>
                                <TableHead>{{ t('Published') }}</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            <TableRow
                                v-for="version in versions"
                                :key="version.id"
                            >
                                <TableCell class="font-medium"
                                    >v{{ version.number }}
                                    <span
                                        class="text-xs text-muted-foreground"
                                        >{{
                                            t(
                                                version.reason === 'initial'
                                                    ? 'initial'
                                                    : 'reindex',
                                            )
                                        }}</span
                                    ></TableCell
                                >
                                <TableCell>
                                    <Badge
                                        :variant="
                                            versionStatusVariant[version.status]
                                        "
                                        >{{
                                            t(
                                                versionStatusLabels[
                                                    version.status
                                                ] ?? version.status,
                                            )
                                        }}</Badge
                                    >
                                    <span
                                        v-if="version.purged_at"
                                        class="ml-1 text-xs text-muted-foreground"
                                        >{{ t('purged') }}</span
                                    >
                                </TableCell>
                                <TableCell class="text-sm">{{
                                    version.profile
                                }}</TableCell>
                                <TableCell class="text-xs text-muted-foreground"
                                    >{{ version.chunking.target_tokens }}/{{
                                        version.chunking.overlap_tokens
                                    }}/{{
                                        version.chunking.max_tokens
                                    }}</TableCell
                                >
                                <TableCell class="text-right tabular-nums">{{
                                    formatNumber(version.documents)
                                }}</TableCell>
                                <TableCell class="text-right tabular-nums">{{
                                    formatNumber(version.chunks)
                                }}</TableCell>
                                <TableCell class="text-right tabular-nums">{{
                                    formatCost(version.cost)
                                }}</TableCell>
                                <TableCell
                                    class="text-xs text-muted-foreground"
                                    >{{
                                        formatDate(version.published_at)
                                    }}</TableCell
                                >
                            </TableRow>
                            <TableEmpty v-if="!versions.length" :colspan="8">{{
                                t(
                                    'The first version is created with the first sync.',
                                )
                            }}</TableEmpty>
                        </TableBody>
                    </Table>
                </div>
            </TabsContent>

            <TabsContent v-if="can('rag.collections.manage')" value="settings">
                <form
                    class="grid max-w-4xl gap-4"
                    @submit.prevent="saveSettings"
                >
                    <CollectionForm
                        :form="settingsForm"
                        :profiles="profiles"
                        :languages="options.languages"
                        :remote="
                            collection.remote
                                ? {
                                      collections: null,
                                      isolated: true,
                                      error: null,
                                  }
                                : null
                        "
                        editing
                    />
                    <InputError
                        :message="
                            (settingsForm.errors as Record<string, string>).rag
                        "
                    />
                    <div>
                        <Button
                            type="submit"
                            :disabled="settingsForm.processing"
                            >{{ t('Save') }}</Button
                        >
                    </div>
                </form>
            </TabsContent>
        </Tabs>
    </div>

    <SourceDialog
        v-model:open="sourceOpen"
        :collection-id="collection.id"
        :options="options"
        :source="editingSource"
    />

    <Dialog
        :open="filesFor !== null"
        @update:open="(value) => !value && (filesFor = null)"
    >
        <DialogContent>
            <DialogHeader>
                <DialogTitle>{{ t('Add files') }}</DialogTitle>
                <DialogDescription>{{
                    t(
                        'A file with the same name becomes a new version of the same document.',
                    )
                }}</DialogDescription>
            </DialogHeader>
            <Input
                type="file"
                multiple
                accept=".txt,.md,.markdown,.pdf"
                @change="
                    (event: Event) =>
                        (filesForm.files = Array.from(
                            (event.target as HTMLInputElement).files ?? [],
                        ))
                "
            />
            <InputError
                v-for="message in fileErrors"
                :key="message"
                :message="message"
            />
            <DialogFooter>
                <Button variant="outline" @click="filesFor = null">{{
                    t('Cancel')
                }}</Button>
                <Button
                    :disabled="filesForm.processing || !filesForm.files.length"
                    @click="submitFiles"
                    >{{ t('Upload and sync') }}</Button
                >
            </DialogFooter>
        </DialogContent>
    </Dialog>

    <Dialog v-model:open="reindexOpen">
        <DialogContent>
            <DialogHeader>
                <DialogTitle>{{ t('Reindex collection') }}</DialogTitle>
                <DialogDescription>{{
                    t(
                        'Builds a new version with the current profile and chunking from the stored documents. The active version keeps answering until the new one is complete; then the old one is purged.',
                    )
                }}</DialogDescription>
            </DialogHeader>
            <div class="grid gap-2">
                <Label for="reindex-cost">{{
                    t('Maximum cost (USD, optional)')
                }}</Label>
                <Input
                    id="reindex-cost"
                    v-model="reindexForm.max_cost"
                    type="number"
                    min="0"
                    step="0.01"
                    :placeholder="t('Ask me if it is large')"
                />
                <InputError
                    :message="
                        reindexForm.errors.max_cost ??
                        (reindexForm.errors as Record<string, string>)
                            .collection
                    "
                />
            </div>
            <DialogFooter>
                <Button variant="outline" @click="reindexOpen = false">{{
                    t('Cancel')
                }}</Button>
                <Button
                    :disabled="reindexForm.processing"
                    data-test="reindex-confirm"
                    @click="reindex"
                    >{{ t('Start reindex') }}</Button
                >
            </DialogFooter>
        </DialogContent>
    </Dialog>

    <Dialog v-model:open="deleteOpen">
        <DialogContent>
            <DialogHeader>
                <DialogTitle>{{ t('Delete collection') }}</DialogTitle>
                <DialogDescription>{{
                    t(
                        'Every source, document, original and vector of the collection is purged. This cannot be undone.',
                    )
                }}</DialogDescription>
            </DialogHeader>
            <div class="grid gap-2">
                <Label for="delete-confirmation">{{
                    t('Type :slug to confirm', { slug: collection.slug })
                }}</Label>
                <Input
                    id="delete-confirmation"
                    v-model="deleteForm.confirmation"
                    autocomplete="off"
                />
                <InputError :message="deleteForm.errors.confirmation" />
            </div>
            <DialogFooter>
                <Button variant="outline" @click="deleteOpen = false">{{
                    t('Cancel')
                }}</Button>
                <Button
                    variant="destructive"
                    :disabled="
                        deleteForm.confirmation !== collection.slug ||
                        deleteForm.processing
                    "
                    @click="destroy"
                    >{{ t('Delete permanently') }}</Button
                >
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
