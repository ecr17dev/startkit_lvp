<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import {
    Database,
    Library,
    Plus,
    Power,
    PowerOff,
    Stethoscope,
    Trash2,
} from '@lucide/vue';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import InputError from '@/components/InputError.vue';
import CollectionForm from '@/components/knowledge/CollectionForm.vue';
import type { CollectionFormData } from '@/components/knowledge/CollectionForm.vue';
import DiagnosticsList from '@/components/knowledge/DiagnosticsList.vue';
import JobsTable from '@/components/knowledge/JobsTable.vue';
import PageHeader from '@/components/PageHeader.vue';
import {
    Alert,
    AlertDescription,
    AlertTitle,
} from '@starter/ui/components/alert';
import { Badge } from '@starter/ui/components/badge';
import { Button } from '@starter/ui/components/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@starter/ui/components/card';
import { Checkbox } from '@starter/ui/components/checkbox';
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
import { usePermissions } from '@/composables/usePermissions';
import { formatDate, formatNumber, t } from '@/lib/i18n';
import { isOpenJob } from '@/lib/knowledge';
import knowledge from '@/routes/admin/knowledge';
import collectionRoutes from '@/routes/admin/knowledge/collections';
import profileRoutes from '@/routes/admin/knowledge/profiles';
import type {
    CollectionSummary,
    EmbeddingModelOption,
    EmbeddingProfile,
    KnowledgeOptions,
    RagConnection,
    RagJobSummary,
    RagStatus,
} from '@/types';

defineOptions({
    layout: { breadcrumbs: [{ title: 'Knowledge', href: knowledge.index() }] },
});

const props = defineProps<{
    status: RagStatus;
    internal: RagConnection;
    modes: { value: string; label: string }[];
    connections: RagConnection[];
    profiles: EmbeddingProfile[];
    embeddingModels: EmbeddingModelOption[];
    collections: CollectionSummary[];
    jobs: RagJobSummary[];
    options: KnowledgeOptions;
    principals: string[];
}>();

const { can } = usePermissions();

const connection = computed(() => props.status.connection ?? props.internal);
const diagnostics = computed(() => connection.value.diagnostics?.checks ?? []);

const activation = useForm({ mode: 'internal', create_extension: false });
const activate = () =>
    activation.post(knowledge.activate().url, { preserveScroll: true });
const deactivate = () =>
    router.post(knowledge.deactivate().url, {}, { preserveScroll: true });
const diagnose = () =>
    router.post(knowledge.diagnose().url, {}, { preserveScroll: true });

const profileOpen = ref(false);
const profileForm = useForm({
    name: '',
    model_id: null as number | null,
    dimensions: 1536,
    metric: 'cosine',
    normalize: true,
    batch_size: 64,
});

const pickModel = (value: unknown) => {
    profileForm.model_id = value ? Number(value) : null;
    const model = props.embeddingModels.find(
        (item) => item.id === profileForm.model_id,
    );

    if (model?.simulated) {
        profileForm.dimensions = 1024;
    }

    if (model && !profileForm.name) {
        profileForm.name = `${model.label} · ${profileForm.dimensions}d`;
    }
};

const submitProfile = () =>
    profileForm.post(profileRoutes.store().url, {
        preserveScroll: true,
        onSuccess: () => {
            profileOpen.value = false;
            profileForm.reset();
        },
    });

const collectionOpen = ref(false);
const collectionForm = useForm<CollectionFormData>({
    name: '',
    slug: '',
    description: '',
    embedding_profile_id: null,
    language: 'es',
    chunking: { target_tokens: 350, overlap_tokens: 50, max_tokens: 800 },
    search: {
        method: 'hybrid',
        top_k: 6,
        min_score: null,
        min_text_rank: null,
        max_context_tokens: 2000,
    },
    acl: ['everyone'],
    retention_days: null,
});

const openCollection = () => {
    collectionForm.reset();
    collectionForm.embedding_profile_id =
        props.profiles.find((profile) => profile.status === 'ready')?.id ??
        null;
    collectionOpen.value = true;
};

const submitCollection = () =>
    collectionForm
        .transform((data) => ({ ...data, slug: data.slug || null }))
        .post(collectionRoutes.store().url, {
            onSuccess: () => (collectionOpen.value = false),
        });

const readyProfiles = computed(() =>
    props.profiles.filter((profile) => profile.status === 'ready'),
);

let timer: ReturnType<typeof setInterval> | undefined;

// Follow the queue while jobs are open...
onMounted(() => {
    timer = setInterval(() => {
        if (
            props.jobs.some(
                (job) => isOpenJob(job.status) && job.status !== 'paused',
            )
        ) {
            router.reload({ only: ['jobs', 'collections'] });
        }
    }, 3000);
});
onBeforeUnmount(() => clearInterval(timer));
</script>

<template>
    <Head :title="t('Knowledge')" />

    <div class="flex flex-col gap-6 p-4 md:p-6">
        <PageHeader
            :title="t('Knowledge (RAG)')"
            :description="
                t(
                    'Collections of authorized content that agents retrieve and cite. Internal mode stores chunks and vectors in this PostgreSQL with pgvector.',
                )
            "
        >
            <template #icon><Library class="size-5" /></template>
            <Button
                v-if="status.available && can('rag.collections.manage')"
                data-test="new-collection"
                @click="openCollection"
            >
                <Plus class="size-4" />
                {{ t('New collection') }}
            </Button>
        </PageHeader>

        <Alert v-if="!status.feature" variant="destructive">
            <AlertTitle>{{
                t('Knowledge is disabled in this installation')
            }}</AlertTitle>
            <AlertDescription>{{
                t(
                    'Set STARTER_FEATURE_RAG=true in the environment to enable the module. Disabled RAG needs no pgvector and generates no embeddings.',
                )
            }}</AlertDescription>
        </Alert>

        <div class="grid gap-6 xl:grid-cols-5">
            <Card class="min-w-0 xl:col-span-3">
                <CardHeader>
                    <CardTitle class="flex flex-wrap items-center gap-2">
                        <Database class="size-4" />
                        {{ t('Activation and diagnostics') }}
                        <Badge
                            :variant="status.available ? 'default' : 'outline'"
                            data-test="rag-status"
                            >{{
                                status.available
                                    ? t('Active · :mode', { mode: status.mode })
                                    : t('Inactive')
                            }}</Badge
                        >
                        <Badge
                            v-if="connection.pgvector_version"
                            variant="secondary"
                            >pgvector {{ connection.pgvector_version }}</Badge
                        >
                    </CardTitle>
                    <CardDescription>
                        {{
                            t(
                                'Activation checks connectivity, TLS, PostgreSQL version, pgvector, privileges, creates the schema and runs an insert and search test. RAG is enabled only if every check passes.',
                            )
                        }}
                    </CardDescription>
                </CardHeader>
                <CardContent class="grid gap-4">
                    <DiagnosticsList
                        v-if="diagnostics.length"
                        :checks="diagnostics"
                    />
                    <p v-else class="text-sm text-muted-foreground">
                        {{ t('No diagnostics yet.') }}
                    </p>
                    <p
                        v-if="connection.checked_at"
                        class="text-xs text-muted-foreground"
                    >
                        {{
                            t('Last check: :date', {
                                date: formatDate(connection.checked_at),
                            })
                        }}
                    </p>

                    <div
                        v-if="can('settings.update') && status.feature"
                        class="flex flex-wrap items-end gap-3 border-t pt-4"
                    >
                        <template v-if="!status.available">
                            <div class="grid gap-1.5">
                                <Label>{{ t('Mode') }}</Label>
                                <Select v-model="activation.mode">
                                    <SelectTrigger class="w-72">
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem
                                            v-for="mode in modes.filter(
                                                (item) =>
                                                    item.value !== 'disabled',
                                            )"
                                            :key="mode.value"
                                            :value="mode.value"
                                            >{{ mode.label }}</SelectItem
                                        >
                                    </SelectContent>
                                </Select>
                            </div>
                            <Label
                                class="flex items-center gap-2 text-sm font-normal"
                            >
                                <Checkbox
                                    v-model="activation.create_extension"
                                />
                                {{ t('Try CREATE EXTENSION vector') }}
                            </Label>
                            <Button
                                :disabled="activation.processing"
                                data-test="rag-activate"
                                @click="activate"
                            >
                                <Power class="size-4" />
                                {{ t('Diagnose and activate') }}
                            </Button>
                        </template>
                        <template v-else>
                            <Button variant="outline" @click="diagnose">
                                <Stethoscope class="size-4" />
                                {{ t('Run diagnostics') }}
                            </Button>
                            <ConfirmDialog
                                :title="t('Disable knowledge?')"
                                :description="
                                    t(
                                        'New jobs and retrieval stop and open jobs are cancelled cooperatively. Documents, chunks and tables are kept.',
                                    )
                                "
                                :confirm-text="t('Disable')"
                                @confirm="deactivate"
                            >
                                <Button variant="outline">
                                    <PowerOff class="size-4" />
                                    {{ t('Disable') }}
                                </Button>
                            </ConfirmDialog>
                        </template>
                        <InputError :message="activation.errors.mode" />
                    </div>
                </CardContent>
            </Card>

            <Card class="min-w-0 xl:col-span-2">
                <CardHeader>
                    <CardTitle class="flex items-center justify-between gap-2">
                        {{ t('Embedding profiles') }}
                        <Button
                            v-if="
                                status.available &&
                                can('rag.collections.manage')
                            "
                            size="sm"
                            variant="outline"
                            data-test="new-profile"
                            @click="profileOpen = true"
                        >
                            <Plus class="size-4" />
                            {{ t('New') }}
                        </Button>
                    </CardTitle>
                    <CardDescription>{{
                        t(
                            'A profile is a vector space (model, dimensions, metric). Queries always use the profile of the indexed documents.',
                        )
                    }}</CardDescription>
                </CardHeader>
                <CardContent class="grid gap-3">
                    <div
                        v-for="profile in profiles"
                        :key="profile.id"
                        class="flex items-start justify-between gap-2 rounded-md border p-3 text-sm"
                    >
                        <div class="grid min-w-0 gap-0.5">
                            <span
                                class="flex flex-wrap items-center gap-1.5 font-medium"
                            >
                                {{ profile.name }}
                                <Badge
                                    v-if="profile.simulated"
                                    variant="outline"
                                    >{{ t('Simulated') }}</Badge
                                >
                                <Badge
                                    :variant="
                                        profile.status === 'ready'
                                            ? 'secondary'
                                            : 'destructive'
                                    "
                                    >{{ profile.status }}</Badge
                                >
                            </span>
                            <span
                                class="truncate text-xs text-muted-foreground"
                            >
                                {{ profile.model }} · {{ profile.dimensions }}d
                                · {{ profile.metric }} ·
                                {{
                                    profile.storage?.exact
                                        ? t('exact search')
                                        : (profile.storage?.index ?? '—')
                                }}
                            </span>
                            <span
                                v-if="profile.error"
                                class="text-xs text-destructive"
                                >{{ profile.error }}</span
                            >
                        </div>
                        <ConfirmDialog
                            v-if="
                                can('rag.collections.manage') &&
                                !profile.collections
                            "
                            :title="t('Delete profile?')"
                            :description="
                                t(
                                    'Its vector table is dropped. Only possible when no collection uses it.',
                                )
                            "
                            @confirm="
                                router.delete(
                                    profileRoutes.destroy(profile.id).url,
                                    { preserveScroll: true },
                                )
                            "
                        >
                            <Button
                                variant="ghost"
                                size="icon"
                                :aria-label="t('Delete')"
                            >
                                <Trash2 class="size-4" />
                            </Button>
                        </ConfirmDialog>
                    </div>
                    <p
                        v-if="!profiles.length"
                        class="text-sm text-muted-foreground"
                    >
                        {{
                            status.available
                                ? t('Create a profile to start a collection.')
                                : t('Activate knowledge first.')
                        }}
                    </p>
                </CardContent>
            </Card>
        </div>

        <section class="grid gap-3">
            <h2 class="text-lg font-semibold">{{ t('Collections') }}</h2>
            <div class="overflow-x-auto rounded-lg border">
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>{{ t('Collection') }}</TableHead>
                            <TableHead>{{ t('Profile') }}</TableHead>
                            <TableHead class="text-right">{{
                                t('Sources')
                            }}</TableHead>
                            <TableHead class="text-right">{{
                                t('Documents')
                            }}</TableHead>
                            <TableHead class="text-right">{{
                                t('Chunks')
                            }}</TableHead>
                            <TableHead>{{ t('Version') }}</TableHead>
                            <TableHead>{{ t('Access') }}</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        <TableRow
                            v-for="collection in collections"
                            :key="collection.id"
                        >
                            <TableCell>
                                <Link
                                    :href="collectionRoutes.show(collection.id)"
                                    class="font-medium hover:underline"
                                    :data-test="`collection-${collection.slug}`"
                                    >{{ collection.name }}</Link
                                >
                                <div class="text-xs text-muted-foreground">
                                    {{ collection.slug }}
                                    <Badge
                                        v-if="collection.status === 'deleting'"
                                        variant="destructive"
                                        class="ml-1"
                                        >{{ t('Deleting') }}</Badge
                                    >
                                    <Badge
                                        v-if="collection.open_jobs"
                                        variant="secondary"
                                        class="ml-1"
                                        >{{
                                            t(':n jobs', {
                                                n: collection.open_jobs,
                                            })
                                        }}</Badge
                                    >
                                </div>
                            </TableCell>
                            <TableCell class="text-sm">{{
                                collection.profile
                            }}</TableCell>
                            <TableCell class="text-right tabular-nums">{{
                                collection.sources
                            }}</TableCell>
                            <TableCell class="text-right tabular-nums">{{
                                formatNumber(collection.documents)
                            }}</TableCell>
                            <TableCell class="text-right tabular-nums">{{
                                formatNumber(
                                    collection.active_version?.chunks ?? 0,
                                )
                            }}</TableCell>
                            <TableCell class="text-sm">
                                <span v-if="collection.active_version"
                                    >v{{
                                        collection.active_version.number
                                    }}</span
                                >
                                <span v-else class="text-muted-foreground"
                                    >—</span
                                >
                                <Badge
                                    v-if="collection.building_version"
                                    variant="secondary"
                                    class="ml-1"
                                    >{{ t('reindexing') }}</Badge
                                >
                                <Badge
                                    v-else-if="collection.needs_reindex"
                                    variant="outline"
                                    class="ml-1"
                                    >{{ t('needs reindex') }}</Badge
                                >
                            </TableCell>
                            <TableCell class="text-xs text-muted-foreground">{{
                                collection.acl.join(', ')
                            }}</TableCell>
                        </TableRow>
                        <TableEmpty v-if="!collections.length" :colspan="7">{{
                            t('No collections yet.')
                        }}</TableEmpty>
                    </TableBody>
                </Table>
            </div>
        </section>

        <section v-if="jobs.length" class="grid gap-3">
            <h2 class="text-lg font-semibold">{{ t('Recent jobs') }}</h2>
            <JobsTable :jobs="jobs" :can-manage="can('rag.jobs.manage')" />
        </section>
    </div>

    <Dialog v-model:open="profileOpen">
        <DialogContent>
            <DialogHeader>
                <DialogTitle>{{ t('New embedding profile') }}</DialogTitle>
                <DialogDescription>{{
                    t(
                        'Model, dimensions and metric cannot change later: a different space is a new profile and a reindex.',
                    )
                }}</DialogDescription>
            </DialogHeader>
            <form
                id="profile-form"
                class="grid gap-4"
                @submit.prevent="submitProfile"
            >
                <div class="grid gap-2">
                    <Label>{{ t('Embeddings model') }}</Label>
                    <Select
                        :model-value="
                            profileForm.model_id
                                ? String(profileForm.model_id)
                                : ''
                        "
                        @update:model-value="pickModel"
                    >
                        <SelectTrigger data-test="profile-model">
                            <SelectValue :placeholder="t('Choose a model')" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem
                                v-for="model in embeddingModels"
                                :key="model.id"
                                :value="String(model.id)"
                                >{{ model.label }} ·
                                {{ model.connection }}</SelectItem
                            >
                        </SelectContent>
                    </Select>
                    <p
                        v-if="!embeddingModels.length"
                        class="text-xs text-muted-foreground"
                    >
                        {{
                            t(
                                'Enable a model with the embeddings function in the model catalog.',
                            )
                        }}
                    </p>
                    <InputError :message="profileForm.errors.model_id" />
                </div>
                <div class="grid gap-2">
                    <Label for="profile-name">{{ t('Name') }}</Label>
                    <Input
                        id="profile-name"
                        v-model="profileForm.name"
                        required
                    />
                    <InputError :message="profileForm.errors.name" />
                </div>
                <div class="grid gap-4 sm:grid-cols-3">
                    <div class="grid gap-2">
                        <Label for="profile-dimensions">{{
                            t('Dimensions')
                        }}</Label>
                        <Input
                            id="profile-dimensions"
                            v-model.number="profileForm.dimensions"
                            type="number"
                            min="2"
                        />
                        <InputError :message="profileForm.errors.dimensions" />
                    </div>
                    <div class="grid gap-2">
                        <Label>{{ t('Metric') }}</Label>
                        <Select v-model="profileForm.metric">
                            <SelectTrigger><SelectValue /></SelectTrigger>
                            <SelectContent>
                                <SelectItem value="cosine">{{
                                    t('Cosine')
                                }}</SelectItem>
                                <SelectItem value="l2">L2</SelectItem>
                                <SelectItem value="inner_product">{{
                                    t('Inner product')
                                }}</SelectItem>
                            </SelectContent>
                        </Select>
                    </div>
                    <div class="grid gap-2">
                        <Label for="profile-batch">{{ t('Batch size') }}</Label>
                        <Input
                            id="profile-batch"
                            v-model.number="profileForm.batch_size"
                            type="number"
                            min="1"
                            max="512"
                        />
                    </div>
                </div>
                <Label class="flex items-center gap-2 text-sm font-normal">
                    <Checkbox v-model="profileForm.normalize" />
                    {{ t('Normalize vectors (unit length)') }}
                </Label>
                <InputError
                    :message="
                        (profileForm.errors as Record<string, string>).rag
                    "
                />
            </form>
            <DialogFooter>
                <Button variant="outline" @click="profileOpen = false">{{
                    t('Cancel')
                }}</Button>
                <Button
                    type="submit"
                    form="profile-form"
                    :disabled="profileForm.processing"
                    data-test="profile-submit"
                    >{{ t('Create profile') }}</Button
                >
            </DialogFooter>
        </DialogContent>
    </Dialog>

    <Dialog v-model:open="collectionOpen">
        <DialogContent class="max-h-[90vh] overflow-y-auto sm:max-w-3xl">
            <DialogHeader>
                <DialogTitle>{{ t('New collection') }}</DialogTitle>
                <DialogDescription>{{
                    t(
                        'Sources, documents and their chunks belong to a collection.',
                    )
                }}</DialogDescription>
            </DialogHeader>
            <form id="collection-form" @submit.prevent="submitCollection">
                <CollectionForm
                    :form="collectionForm"
                    :profiles="readyProfiles"
                    :languages="options.languages"
                />
                <InputError
                    :message="
                        (collectionForm.errors as Record<string, string>).rag
                    "
                />
            </form>
            <DialogFooter>
                <Button variant="outline" @click="collectionOpen = false">{{
                    t('Cancel')
                }}</Button>
                <Button
                    type="submit"
                    form="collection-form"
                    :disabled="collectionForm.processing"
                    data-test="collection-submit"
                    >{{ t('Create collection') }}</Button
                >
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
