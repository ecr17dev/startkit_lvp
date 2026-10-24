<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import {
    Database,
    Globe,
    Pencil,
    Plus,
    Power,
    Server,
    Stethoscope,
    Trash2,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import InputError from '@/components/InputError.vue';
import DiagnosticsList from '@/components/knowledge/DiagnosticsList.vue';
import PageHeader from '@/components/PageHeader.vue';
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
import { formatDate, t } from '@/lib/i18n';
import knowledge from '@/routes/admin/knowledge';
import connectionRoutes from '@/routes/admin/knowledge/connections';
import type { RagConnectionDetail, RagStatus } from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Knowledge', href: knowledge.index() },
            { title: 'Connections', href: connectionRoutes.index() },
        ],
    },
});

const props = defineProps<{
    status: RagStatus;
    connections: RagConnectionDetail[];
    sslModes: string[];
}>();

const kindLabels: Record<string, string> = {
    internal: 'Internal PostgreSQL',
    pgsql: 'External PostgreSQL',
    api: 'Retrieval API',
};

const kindIcons = { internal: Server, pgsql: Database, api: Globe };

const capabilityLabels: Record<string, string> = {
    query: 'Search',
    list_collections: 'List collections',
    list_documents: 'List documents',
    ingest: 'Ingestion',
    delete: 'Deletions',
    jobs: 'Jobs',
    metrics: 'Metrics',
    chunks: 'Passages',
};

const statusVariant: Record<
    string,
    'default' | 'secondary' | 'outline' | 'destructive'
> = {
    ready: 'default',
    pending: 'outline',
    failed: 'destructive',
    disabled: 'outline',
};

const statusLabels: Record<string, string> = {
    ready: 'Ready',
    pending: 'Pending diagnostics',
    failed: 'Failed',
    disabled: 'Disabled',
};

const address = (connection: RagConnectionDetail) => {
    if (connection.kind === 'internal') {
        return t("This application's PostgreSQL");
    }

    if (connection.kind === 'api') {
        return connection.config.base_url ?? '';
    }

    const config = connection.config;

    return `${config.host}:${config.port}/${config.database} · ${t('schema')} ${config.schema ?? 'public'} · sslmode=${config.sslmode}`;
};

// Create and edit...
const editing = ref<RagConnectionDetail | null>(null);
const dialogOpen = ref(false);
const form = useForm({
    kind: 'pgsql' as 'pgsql' | 'api',
    label: '',
    config: {
        host: '',
        port: 5432 as number | string,
        database: '',
        schema: 'rag',
        sslmode: 'require',
        sslrootcert: '',
        connect_timeout: 5 as number | string,
        statement_timeout_ms: 15000 as number | string,
        read_only: false,
        base_url: '',
        timeout: 10 as number | string,
        enforces_acl: false,
    },
    credentials: { username: '', password: '', token: '' },
});

const openDialog = (connection: RagConnectionDetail | null) => {
    editing.value = connection;
    form.clearErrors();
    form.reset();

    if (connection) {
        form.kind = connection.kind === 'api' ? 'api' : 'pgsql';
        form.label = connection.label;
        form.config = { ...form.config, ...connection.config };
        form.config.sslrootcert = connection.config.sslrootcert ?? '';
        // Secrets are never sent back: empty keeps the stored value...
        form.credentials = {
            username: connection.credentials.username ?? '',
            password: '',
            token: '',
        };
    }

    dialogOpen.value = true;
};

const submit = () => {
    const options = {
        preserveScroll: true,
        onSuccess: () => (dialogOpen.value = false),
    };
    const payload = form.transform((data) => ({
        kind: data.kind,
        label: data.label,
        config:
            data.kind === 'pgsql'
                ? {
                      host: data.config.host,
                      port: Number(data.config.port),
                      database: data.config.database,
                      schema: data.config.schema || null,
                      sslmode: data.config.sslmode,
                      sslrootcert: data.config.sslrootcert || null,
                      connect_timeout: Number(data.config.connect_timeout),
                      statement_timeout_ms: Number(
                          data.config.statement_timeout_ms,
                      ),
                      read_only: data.config.read_only,
                  }
                : {
                      base_url: data.config.base_url,
                      timeout: Number(data.config.timeout),
                      enforces_acl: data.config.enforces_acl,
                  },
        credentials:
            data.kind === 'pgsql'
                ? {
                      username: data.credentials.username || null,
                      password: data.credentials.password || null,
                  }
                : { token: data.credentials.token || null },
    }));

    if (editing.value) {
        payload.put(connectionRoutes.update(editing.value.id).url, options);
    } else {
        payload.post(connectionRoutes.store().url, options);
    }
};

const diagnose = (connection: RagConnectionDetail) =>
    router.post(
        connectionRoutes.diagnose(connection.id).url,
        {},
        { preserveScroll: true },
    );

const remove = (connection: RagConnectionDetail) =>
    router.delete(connectionRoutes.destroy(connection.id).url, {
        preserveScroll: true,
    });

// Activation: explicit administrative step...
const activating = ref<RagConnectionDetail | null>(null);
const activation = useForm({ create_extension: false });
const modeFor = (connection: RagConnectionDetail) =>
    ({ internal: 'internal', pgsql: 'external_pgsql', api: 'external_api' })[
        connection.kind
    ];

const activate = () => {
    const connection = activating.value;

    if (!connection) {
        return;
    }

    activation
        .transform((data) => ({
            mode: modeFor(connection),
            connection_id:
                connection.kind === 'internal' ? null : connection.id,
            create_extension: data.create_extension,
        }))
        .post(knowledge.activate().url, {
            preserveScroll: true,
            onFinish: () => (activating.value = null),
        });
};

const activeLabel = computed(
    () => props.connections.find((connection) => connection.active)?.label,
);
</script>

<template>
    <Head :title="t('Knowledge connections')" />

    <div class="flex flex-col gap-6 p-4 md:p-6">
        <PageHeader
            :title="t('Knowledge connections')"
            :description="
                t(
                    'Where knowledge is stored and searched. Diagnostics never change an external database; activating is the explicit step that prepares its schema and switches the mode.',
                )
            "
        >
            <template #icon><Database class="size-5" /></template>
            <Button data-test="new-connection" @click="openDialog(null)">
                <Plus class="size-4" />
                {{ t('New connection') }}
            </Button>
        </PageHeader>

        <p class="text-sm text-muted-foreground">
            {{
                activeLabel
                    ? t(
                          'New collections are created on the active connection: :label. Existing collections keep their own.',
                          { label: activeLabel },
                      )
                    : t('Knowledge is not active.')
            }}
        </p>

        <div class="grid gap-4 xl:grid-cols-2">
            <Card
                v-for="connection in connections"
                :key="connection.id"
                class="min-w-0"
                :data-test="`connection-${connection.kind}`"
            >
                <CardHeader>
                    <CardTitle class="flex flex-wrap items-center gap-2">
                        <component
                            :is="kindIcons[connection.kind]"
                            class="size-4"
                        />
                        {{ connection.label }}
                        <Badge variant="secondary">{{
                            t(kindLabels[connection.kind])
                        }}</Badge>
                        <Badge :variant="statusVariant[connection.status]">{{
                            t(statusLabels[connection.status])
                        }}</Badge>
                        <Badge v-if="connection.active">{{
                            t('Active')
                        }}</Badge>
                        <Badge v-if="connection.read_only" variant="outline">{{
                            t('Read only')
                        }}</Badge>
                    </CardTitle>
                    <CardDescription class="font-mono text-xs break-all">{{
                        address(connection)
                    }}</CardDescription>
                </CardHeader>
                <CardContent class="grid gap-4">
                    <div
                        v-if="connection.capabilities.length"
                        class="flex flex-wrap gap-1"
                    >
                        <Badge
                            v-for="capability in connection.capabilities"
                            :key="capability"
                            variant="outline"
                            class="font-normal"
                            >{{
                                t(capabilityLabels[capability] ?? capability)
                            }}</Badge
                        >
                    </div>
                    <DiagnosticsList
                        v-if="connection.diagnostics?.checks?.length"
                        :checks="connection.diagnostics.checks"
                    />
                    <p v-else class="text-sm text-muted-foreground">
                        {{ t('No diagnostics yet.') }}
                    </p>
                    <p class="text-xs text-muted-foreground">
                        {{
                            t(':collections collections · :profiles profiles', {
                                collections: connection.collections,
                                profiles: connection.profiles,
                            })
                        }}
                        <template v-if="connection.checked_at">
                            ·
                            {{
                                t('Last check: :date', {
                                    date: formatDate(connection.checked_at),
                                })
                            }}</template
                        >
                    </p>
                    <div class="flex flex-wrap gap-2 border-t pt-4">
                        <Button
                            variant="outline"
                            size="sm"
                            :data-test="`diagnose-${connection.id}`"
                            @click="diagnose(connection)"
                        >
                            <Stethoscope class="size-4" />
                            {{ t('Run diagnostics') }}
                        </Button>
                        <Button
                            v-if="!connection.active && status.feature"
                            size="sm"
                            :data-test="`activate-${connection.id}`"
                            @click="activating = connection"
                        >
                            <Power class="size-4" />
                            {{ t('Activate') }}
                        </Button>
                        <template v-if="connection.kind !== 'internal'">
                            <Button
                                variant="outline"
                                size="sm"
                                @click="openDialog(connection)"
                            >
                                <Pencil class="size-4" />
                                {{ t('Edit') }}
                            </Button>
                            <ConfirmDialog
                                v-if="
                                    !connection.active &&
                                    !connection.collections &&
                                    !connection.profiles
                                "
                                :title="t('Delete connection?')"
                                :description="
                                    t(
                                        'Only the connection settings are deleted; nothing is changed on the external database.',
                                    )
                                "
                                @confirm="remove(connection)"
                            >
                                <Button
                                    variant="outline"
                                    size="sm"
                                    :aria-label="t('Delete')"
                                >
                                    <Trash2 class="size-4" />
                                </Button>
                            </ConfirmDialog>
                        </template>
                    </div>
                </CardContent>
            </Card>
        </div>
    </div>

    <Dialog v-model:open="dialogOpen">
        <DialogContent class="max-h-[90vh] overflow-y-auto sm:max-w-xl">
            <DialogHeader>
                <DialogTitle>{{
                    editing ? t('Edit connection') : t('New connection')
                }}</DialogTitle>
                <DialogDescription>{{
                    t(
                        'Secrets are stored encrypted, or referenced as env:STARTER_RAG_SECRET_NAME. They are never shown again; leave them empty to keep them.',
                    )
                }}</DialogDescription>
            </DialogHeader>
            <form
                id="connection-form"
                class="grid gap-4"
                @submit.prevent="submit"
            >
                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="grid gap-2">
                        <Label>{{ t('Type') }}</Label>
                        <Select v-model="form.kind" :disabled="!!editing">
                            <SelectTrigger
                                class="w-full"
                                data-test="connection-kind"
                                ><SelectValue
                            /></SelectTrigger>
                            <SelectContent>
                                <SelectItem value="pgsql">{{
                                    t('External PostgreSQL')
                                }}</SelectItem>
                                <SelectItem value="api">{{
                                    t('Retrieval API')
                                }}</SelectItem>
                            </SelectContent>
                        </Select>
                        <InputError
                            :message="
                                (form.errors as Record<string, string>).kind
                            "
                        />
                    </div>
                    <div class="grid gap-2">
                        <Label for="connection-label">{{ t('Name') }}</Label>
                        <Input
                            id="connection-label"
                            v-model="form.label"
                            required
                            data-test="connection-label"
                        />
                        <InputError :message="form.errors.label" />
                    </div>
                </div>

                <template v-if="form.kind === 'pgsql'">
                    <div class="grid gap-4 sm:grid-cols-3">
                        <div class="grid gap-2 sm:col-span-2">
                            <Label for="connection-host">{{ t('Host') }}</Label>
                            <Input
                                id="connection-host"
                                v-model="form.config.host"
                                required
                                data-test="connection-host"
                            />
                            <InputError
                                :message="
                                    (form.errors as Record<string, string>)[
                                        'config.host'
                                    ]
                                "
                            />
                        </div>
                        <div class="grid gap-2">
                            <Label for="connection-port">{{ t('Port') }}</Label>
                            <Input
                                id="connection-port"
                                v-model="form.config.port"
                                type="number"
                                min="1"
                                max="65535"
                            />
                        </div>
                        <div class="grid gap-2">
                            <Label for="connection-database">{{
                                t('Database')
                            }}</Label>
                            <Input
                                id="connection-database"
                                v-model="form.config.database"
                                required
                                data-test="connection-database"
                            />
                        </div>
                        <div class="grid gap-2">
                            <Label for="connection-schema">{{
                                t('Schema')
                            }}</Label>
                            <Input
                                id="connection-schema"
                                v-model="form.config.schema"
                                placeholder="rag"
                            />
                            <InputError
                                :message="
                                    (form.errors as Record<string, string>)[
                                        'config.schema'
                                    ]
                                "
                            />
                        </div>
                        <div class="grid gap-2">
                            <Label>TLS (sslmode)</Label>
                            <Select v-model="form.config.sslmode">
                                <SelectTrigger class="w-full"
                                    ><SelectValue
                                /></SelectTrigger>
                                <SelectContent>
                                    <SelectItem
                                        v-for="mode in sslModes"
                                        :key="mode"
                                        :value="mode"
                                        >{{ mode }}</SelectItem
                                    >
                                </SelectContent>
                            </Select>
                        </div>
                    </div>
                    <div class="grid gap-2">
                        <Label for="connection-ca">{{
                            t('CA certificate path (verify-ca, verify-full)')
                        }}</Label>
                        <Input
                            id="connection-ca"
                            v-model="form.config.sslrootcert"
                            placeholder="/etc/ssl/certs/rag-ca.pem"
                        />
                    </div>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div class="grid gap-2">
                            <Label for="connection-user">{{ t('User') }}</Label>
                            <Input
                                id="connection-user"
                                v-model="form.credentials.username"
                                autocomplete="off"
                                data-test="connection-username"
                            />
                            <InputError
                                :message="
                                    (form.errors as Record<string, string>)[
                                        'credentials.username'
                                    ]
                                "
                            />
                        </div>
                        <div class="grid gap-2">
                            <Label for="connection-password">{{
                                t('Password')
                            }}</Label>
                            <Input
                                id="connection-password"
                                v-model="form.credentials.password"
                                type="password"
                                autocomplete="new-password"
                                :placeholder="
                                    editing
                                        ? t('Unchanged')
                                        : 'env:STARTER_RAG_SECRET_…'
                                "
                                data-test="connection-password"
                            />
                            <InputError
                                :message="
                                    (form.errors as Record<string, string>)[
                                        'credentials.password'
                                    ]
                                "
                            />
                        </div>
                        <div class="grid gap-2">
                            <Label for="connection-connect">{{
                                t('Connection timeout (s)')
                            }}</Label>
                            <Input
                                id="connection-connect"
                                v-model="form.config.connect_timeout"
                                type="number"
                                min="1"
                                max="60"
                            />
                        </div>
                        <div class="grid gap-2">
                            <Label for="connection-statement">{{
                                t('Query timeout (ms)')
                            }}</Label>
                            <Input
                                id="connection-statement"
                                v-model="form.config.statement_timeout_ms"
                                type="number"
                                min="1000"
                                max="120000"
                            />
                        </div>
                    </div>
                    <Label class="flex items-start gap-2 text-sm font-normal">
                        <Checkbox
                            v-model="form.config.read_only"
                            class="mt-0.5"
                        />
                        <span>
                            {{ t('Read only') }}
                            <span class="block text-xs text-muted-foreground">{{
                                t(
                                    'Only search the indexes another system maintains: ingestion, deletions and reindexing are hidden, and the database rejects writes.',
                                )
                            }}</span>
                        </span>
                    </Label>
                </template>

                <template v-else>
                    <div class="grid gap-2">
                        <Label for="connection-url">{{ t('Base URL') }}</Label>
                        <Input
                            id="connection-url"
                            v-model="form.config.base_url"
                            placeholder="https://search.example.com/v1"
                            data-test="connection-url"
                        />
                        <InputError
                            :message="
                                (form.errors as Record<string, string>)[
                                    'config.base_url'
                                ]
                            "
                        />
                    </div>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div class="grid gap-2">
                            <Label for="connection-token">{{
                                t('Bearer token')
                            }}</Label>
                            <Input
                                id="connection-token"
                                v-model="form.credentials.token"
                                type="password"
                                autocomplete="new-password"
                                :placeholder="
                                    editing
                                        ? t('Unchanged')
                                        : 'env:STARTER_RAG_SECRET_…'
                                "
                                data-test="connection-token"
                            />
                            <InputError
                                :message="
                                    (form.errors as Record<string, string>)[
                                        'credentials.token'
                                    ]
                                "
                            />
                        </div>
                        <div class="grid gap-2">
                            <Label for="connection-timeout">{{
                                t('Timeout (s)')
                            }}</Label>
                            <Input
                                id="connection-timeout"
                                v-model="form.config.timeout"
                                type="number"
                                min="1"
                                max="60"
                            />
                        </div>
                    </div>
                    <Label class="flex items-start gap-2 text-sm font-normal">
                        <Checkbox
                            v-model="form.config.enforces_acl"
                            class="mt-0.5"
                        />
                        <span>
                            {{ t('The service enforces the access lists') }}
                            <span class="block text-xs text-muted-foreground">{{
                                t(
                                    'It receives the principals of each user and only returns what they may read. Without this guarantee only public collections can use the connection.',
                                )
                            }}</span>
                        </span>
                    </Label>
                </template>
            </form>
            <DialogFooter>
                <Button variant="outline" @click="dialogOpen = false">{{
                    t('Cancel')
                }}</Button>
                <Button
                    type="submit"
                    form="connection-form"
                    :disabled="form.processing"
                    data-test="connection-submit"
                    >{{ t('Save') }}</Button
                >
            </DialogFooter>
        </DialogContent>
    </Dialog>

    <Dialog
        :open="!!activating"
        @update:open="(open) => !open && (activating = null)"
    >
        <DialogContent class="sm:max-w-lg">
            <DialogHeader>
                <DialogTitle>{{
                    t('Activate :label?', { label: activating?.label ?? '' })
                }}</DialogTitle>
                <DialogDescription>{{
                    activating?.read_only
                        ? t(
                              'The diagnostics run without creating anything (read only). Knowledge switches to this connection only if every check passes.',
                          )
                        : t(
                              'The diagnostics run and, if they pass, the schema, the knowledge tables and the vector indexes are created on this database. New collections will use this connection.',
                          )
                }}</DialogDescription>
            </DialogHeader>
            <Label
                v-if="activating?.kind !== 'api' && !activating?.read_only"
                class="flex items-center gap-2 text-sm font-normal"
            >
                <Checkbox v-model="activation.create_extension" />
                {{ t('Try CREATE EXTENSION vector') }}
            </Label>
            <InputError
                :message="(activation.errors as Record<string, string>).mode"
            />
            <DialogFooter>
                <Button variant="outline" @click="activating = null">{{
                    t('Cancel')
                }}</Button>
                <Button
                    :disabled="activation.processing"
                    data-test="activate-confirm"
                    @click="activate"
                    >{{ t('Diagnose and activate') }}</Button
                >
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
