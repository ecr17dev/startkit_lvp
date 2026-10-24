<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { CircleCheck, CircleX, Play, Waypoints } from '@lucide/vue';
import { ref } from 'vue';
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
import {
    Table,
    TableBody,
    TableCell,
    TableEmpty,
    TableHead,
    TableHeader,
    TableRow,
} from '@starter/ui/components/table';
import { formatNumber, t } from '@/lib/i18n';
import integrations from '@/routes/admin/integrations';
import postgrestRoutes from '@/routes/admin/integrations/postgrest';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'PostgREST', href: integrations.postgrest() }],
    },
});

type TestResult =
    | {
          ok: boolean;
          status: number[];
          whoami: {
              user_id: string | null;
              principals: string[];
              role: string;
          };
          collections: {
              id: number;
              slug: string;
              name: string;
              documents: number;
          }[];
          latency_ms: number;
      }
    | { ok: false; error: string };

defineProps<{
    enabled: boolean;
    url: string;
    secretConfigured: boolean;
    ttl: number;
    directTokens: boolean;
    roles: { authenticator: string; anon: string; user: string };
    install: {
        schema: boolean;
        objects: { name: string; type: string }[];
        roles: Record<string, boolean>;
        user_can_select: boolean;
        anon_can_select: boolean;
        can_create_roles: boolean;
    } | null;
    installError: string | null;
    objects: string[];
}>();

const result = ref<TestResult | null>(null);
const testing = ref(false);

const test = () => {
    testing.value = true;
    router.post(
        postgrestRoutes.test().url,
        {},
        {
            preserveScroll: true,
            onFlash: (flash) =>
                (result.value =
                    ((flash as Record<string, unknown>)
                        .postgrest as TestResult) ?? null),
            onFinish: () => (testing.value = false),
        },
    );
};
</script>

<template>
    <Head title="PostgREST" />

    <div class="flex flex-col gap-6 p-4 md:p-6">
        <PageHeader
            title="PostgREST"
            :description="
                t(
                    'Optional data API over the dedicated api schema: read only views and RPC that filter by the identity and principals of a short JWT signed by this application. Business logic, authorization and audit stay in Laravel.',
                )
            "
        >
            <template #icon><Waypoints class="size-5" /></template>
        </PageHeader>

        <Alert v-if="!enabled">
            <AlertTitle>{{ t('PostgREST is disabled') }}</AlertTitle>
            <AlertDescription>{{
                t(
                    'Set STARTER_FEATURE_POSTGREST=true, POSTGREST_JWT_SECRET (32+ characters, the same as PGRST_JWT_SECRET) and start the postgrest Compose profile. Disabled, it is never required and changes nothing in the application.',
                )
            }}</AlertDescription>
        </Alert>

        <div class="grid gap-6 xl:grid-cols-2">
            <Card class="min-w-0">
                <CardHeader>
                    <CardTitle class="text-base">{{
                        t('Configuration')
                    }}</CardTitle>
                    <CardDescription>{{
                        t(
                            'Server to server: Laravel signs a JWT for each request with the user principals. A browser cookie never authenticates against PostgREST.',
                        )
                    }}</CardDescription>
                </CardHeader>
                <CardContent class="grid gap-2 text-sm">
                    <div class="flex justify-between gap-4">
                        <span class="text-muted-foreground">{{
                            t('Address')
                        }}</span>
                        <code class="truncate">{{ url }}</code>
                    </div>
                    <div class="flex items-center justify-between gap-4">
                        <span class="text-muted-foreground">{{
                            t('Signing secret')
                        }}</span>
                        <span class="flex items-center gap-1.5">
                            <component
                                :is="secretConfigured ? CircleCheck : CircleX"
                                class="size-4"
                                :class="
                                    secretConfigured
                                        ? 'text-emerald-600 dark:text-emerald-400'
                                        : 'text-destructive'
                                "
                            />
                            {{
                                secretConfigured
                                    ? t('Configured')
                                    : t('Missing (32+ characters)')
                            }}
                        </span>
                    </div>
                    <div class="flex justify-between gap-4">
                        <span class="text-muted-foreground">{{
                            t('Token lifetime')
                        }}</span>
                        <span>{{ t(':n s', { n: ttl }) }}</span>
                    </div>
                    <div class="flex justify-between gap-4">
                        <span class="text-muted-foreground">{{
                            t('Direct client tokens')
                        }}</span>
                        <Badge
                            :variant="directTokens ? 'default' : 'outline'"
                            >{{
                                directTokens ? t('Enabled') : t('Disabled')
                            }}</Badge
                        >
                    </div>
                    <div class="flex justify-between gap-4">
                        <span class="text-muted-foreground">{{
                            t('Roles')
                        }}</span>
                        <code class="text-xs"
                            >{{ roles.authenticator }} → {{ roles.user }} /
                            {{ roles.anon }}</code
                        >
                    </div>
                </CardContent>
            </Card>

            <Card class="min-w-0">
                <CardHeader>
                    <CardTitle class="text-base">{{
                        t('Published objects')
                    }}</CardTitle>
                    <CardDescription>{{
                        t(
                            'Only the api schema is exposed. php artisan starter:postgrest-install prepares it (and the roles, or prints their SQL with --sql for the database administrator).',
                        )
                    }}</CardDescription>
                </CardHeader>
                <CardContent class="grid gap-3 text-sm">
                    <p v-if="installError" class="text-destructive">
                        {{ installError }}
                    </p>
                    <template v-else-if="install">
                        <ul class="grid gap-1.5" data-test="postgrest-checks">
                            <li
                                v-for="check in [
                                    {
                                        ok: install.schema,
                                        label: t('Schema api installed'),
                                    },
                                    ...Object.entries(install.roles).map(
                                        ([name, exists]) => ({
                                            ok: exists,
                                            label: t('Role :name', { name }),
                                        }),
                                    ),
                                    {
                                        ok: install.user_can_select,
                                        label: t(
                                            'The user role reads the published views',
                                        ),
                                    },
                                    {
                                        ok: !install.anon_can_select,
                                        label: t(
                                            'The anonymous role reads nothing',
                                        ),
                                    },
                                ]"
                                :key="check.label"
                                class="flex items-center gap-2"
                            >
                                <component
                                    :is="check.ok ? CircleCheck : CircleX"
                                    class="size-4 shrink-0"
                                    :class="
                                        check.ok
                                            ? 'text-emerald-600 dark:text-emerald-400'
                                            : 'text-destructive'
                                    "
                                />
                                {{ check.label }}
                            </li>
                        </ul>
                        <div class="flex flex-wrap gap-1">
                            <Badge
                                v-for="object in install.objects"
                                :key="object.name"
                                variant="outline"
                                class="font-mono font-normal"
                                >api.{{ object.name }}
                                <span class="text-muted-foreground"
                                    >· {{ object.type }}</span
                                ></Badge
                            >
                        </div>
                    </template>
                </CardContent>
            </Card>
        </div>

        <Card class="min-w-0">
            <CardHeader>
                <CardTitle
                    class="flex flex-wrap items-center justify-between gap-2 text-base"
                >
                    {{ t('Test as me') }}
                    <Button
                        size="sm"
                        :disabled="!enabled || !secretConfigured || testing"
                        data-test="postgrest-test"
                        @click="test"
                    >
                        <Play class="size-4" />
                        {{ t('Run test') }}
                    </Button>
                </CardTitle>
                <CardDescription>{{
                    t(
                        'Calls rpc/whoami and knowledge_collections through PostgREST with a JWT of your identity: you should only see what the panel shows you.',
                    )
                }}</CardDescription>
            </CardHeader>
            <CardContent>
                <p
                    v-if="!result"
                    class="rounded-md border border-dashed p-6 text-center text-sm text-muted-foreground"
                >
                    {{ t('No test yet.') }}
                </p>
                <p
                    v-else-if="'error' in result"
                    class="text-sm text-destructive"
                >
                    {{ result.error }}
                </p>
                <div v-else class="grid gap-4">
                    <p class="text-xs text-muted-foreground">
                        HTTP {{ result.status.join(' / ') }} ·
                        {{ formatNumber(result.latency_ms) }} ms ·
                        {{ t('role') }} <code>{{ result.whoami.role }}</code> ·
                        {{ t('user') }}
                        <code>{{ result.whoami.user_id }}</code>
                    </p>
                    <div class="flex flex-wrap gap-1">
                        <Badge
                            v-for="principal in result.whoami.principals"
                            :key="principal"
                            variant="secondary"
                            class="font-mono font-normal"
                            >{{ principal }}</Badge
                        >
                    </div>
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>{{ t('Collection') }}</TableHead>
                                <TableHead class="text-right">{{
                                    t('Documents')
                                }}</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            <TableRow
                                v-for="collection in result.collections"
                                :key="collection.id"
                            >
                                <TableCell
                                    >{{ collection.name }}
                                    <code
                                        class="text-xs text-muted-foreground"
                                        >{{ collection.slug }}</code
                                    ></TableCell
                                >
                                <TableCell class="text-right tabular-nums">{{
                                    collection.documents
                                }}</TableCell>
                            </TableRow>
                            <TableEmpty
                                v-if="!result.collections.length"
                                :colspan="2"
                                >{{ t('No collections.') }}</TableEmpty
                            >
                        </TableBody>
                    </Table>
                </div>
            </CardContent>
        </Card>
    </div>
</template>
