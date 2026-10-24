<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import PageHeader from '@/components/PageHeader.vue';
import { Button } from '@starter/ui/components/button';
import { Input } from '@starter/ui/components/input';
import { Label } from '@starter/ui/components/label';
import { RadioGroup, RadioGroupItem } from '@starter/ui/components/radio-group';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@starter/ui/components/select';
import { Spinner } from '@starter/ui/components/spinner';
import { Switch } from '@starter/ui/components/switch';
import { t } from '@/lib/i18n';
import connections from '@/routes/admin/ai/connections';
import type { AiConnection, DriverOption } from '@/types';

const props = defineProps<{
    connection: AiConnection | null;
    drivers: DriverOption[];
    secretRefs: string[];
}>();

defineOptions({
    layout: (props: { connection: AiConnection | null }) => ({
        breadcrumbs: [
            { title: 'AI connections', href: connections.index() },
            {
                title: props.connection?.label ?? 'New connection',
                href: props.connection
                    ? connections.edit(props.connection.id)
                    : connections.create(),
            },
        ],
    }),
});

const editing = computed(() => props.connection !== null);

const form = useForm({
    name: props.connection?.name ?? '',
    label: props.connection?.label ?? '',
    driver: props.connection?.driver ?? 'openai',
    base_url: props.connection?.base_url ?? '',
    credential_mode: props.connection?.credential_mode ?? 'stored',
    secret_ref: props.connection?.secret_ref ?? '',
    api_key: '',
    access_key_id: '',
    secret_access_key: '',
    session_token: '',
    region: props.connection?.options.region ?? '',
    api_version: props.connection?.options.api_version ?? '',
    deployment: props.connection?.options.deployment ?? '',
    is_active: props.connection?.is_active ?? true,
    is_default: props.connection?.is_default ?? false,
});

const driver = computed(
    () => props.drivers.find((item) => item.value === form.driver) ?? null,
);

// Suggest a name from the label when creating...
watch(
    () => form.label,
    (label) => {
        if (!editing.value) {
            form.name = label
                .toLowerCase()
                .normalize('NFD')
                .replace(/[̀-ͯ]/g, '')
                .replace(/[^a-z0-9]+/g, '-')
                .replace(/^-|-$/g, '')
                .slice(0, 64);
        }
    },
);

const credentialLabels: Record<string, string> = {
    api_key: 'API key',
    access_key_id: 'Access key ID',
    secret_access_key: 'Secret access key',
    session_token: 'Session token',
};

const optionLabels: Record<string, string> = {
    region: 'Region',
    api_version: 'API version',
    deployment: 'Deployment',
};

const submit = () => {
    const payload = form.transform((data) => {
        const result: Record<string, unknown> = { ...data };

        // Never send blank secrets: they keep the stored values...
        for (const key of [
            'api_key',
            'access_key_id',
            'secret_access_key',
            'session_token',
        ]) {
            if (!result[key]) {
                delete result[key];
            }
        }

        return result;
    });

    if (props.connection) {
        payload.put(connections.update(props.connection.id).url);
    } else {
        payload.post(connections.store().url);
    }
};
</script>

<template>
    <Head :title="connection?.label ?? t('New connection')" />

    <form
        class="flex max-w-3xl flex-col gap-6 p-4 md:p-6"
        @submit.prevent="submit"
    >
        <PageHeader
            :title="connection ? connection.label : t('New connection')"
            :description="
                t(
                    'Choose the provider, the endpoint and how the key is provided.',
                )
            "
        />

        <div class="grid gap-6 sm:grid-cols-2">
            <div class="grid gap-2">
                <Label for="label">{{ t('Label') }}</Label>
                <Input id="label" v-model="form.label" required />
                <InputError :message="form.errors.label" />
            </div>
            <div class="grid gap-2">
                <Label for="name">{{ t('Identifier') }}</Label>
                <Input
                    id="name"
                    v-model="form.name"
                    required
                    :disabled="editing"
                    class="font-mono"
                />
                <InputError :message="form.errors.name" />
            </div>
        </div>

        <div class="grid gap-6 sm:grid-cols-2">
            <div class="grid gap-2">
                <Label for="driver">{{ t('Provider') }}</Label>
                <Select v-model="form.driver" :disabled="editing">
                    <SelectTrigger id="driver" class="w-full"
                        ><SelectValue
                    /></SelectTrigger>
                    <SelectContent>
                        <SelectItem
                            v-for="item in drivers"
                            :key="item.value"
                            :value="item.value"
                            >{{ item.label }}</SelectItem
                        >
                    </SelectContent>
                </Select>
                <InputError :message="form.errors.driver" />
            </div>
            <div class="grid gap-2">
                <Label for="base_url">{{ t('Endpoint') }}</Label>
                <Input
                    id="base_url"
                    v-model="form.base_url"
                    type="url"
                    :required="driver?.requires_base_url"
                    :placeholder="driver?.default_url ?? 'https://…'"
                />
                <p class="text-xs text-muted-foreground">
                    {{
                        driver?.requires_base_url
                            ? t('Required for this provider.')
                            : t('Leave empty to use the provider default.')
                    }}
                </p>
                <InputError :message="form.errors.base_url" />
            </div>
        </div>

        <div v-if="driver?.options.length" class="grid gap-6 sm:grid-cols-2">
            <div
                v-for="option in driver.options"
                :key="option"
                class="grid gap-2"
            >
                <Label :for="option">{{
                    t(optionLabels[option] ?? option)
                }}</Label>
                <Input
                    :id="option"
                    v-model="
                        form[option as 'region' | 'api_version' | 'deployment']
                    "
                />
                <InputError :message="form.errors[option as 'region']" />
            </div>
        </div>

        <fieldset class="grid gap-4 rounded-lg border p-4">
            <legend class="px-1 text-sm font-medium">
                {{ t('Credential') }}
            </legend>

            <RadioGroup
                v-model="form.credential_mode"
                class="grid gap-2 sm:grid-cols-2"
            >
                <Label
                    class="flex items-start gap-3 rounded-md border p-3 font-normal"
                >
                    <RadioGroupItem value="stored" class="mt-0.5" />
                    <span class="grid gap-1">
                        <span class="font-medium">{{
                            t('Store encrypted')
                        }}</span>
                        <span class="text-xs text-muted-foreground">{{
                            t('Encrypted with the application key.')
                        }}</span>
                    </span>
                </Label>
                <Label
                    class="flex items-start gap-3 rounded-md border p-3 font-normal"
                >
                    <RadioGroupItem value="env" class="mt-0.5" />
                    <span class="grid gap-1">
                        <span class="font-medium">{{
                            t('Deployment secret')
                        }}</span>
                        <span class="text-xs text-muted-foreground">{{
                            t('Read from an environment variable at runtime.')
                        }}</span>
                    </span>
                </Label>
            </RadioGroup>

            <div v-if="form.credential_mode === 'env'" class="grid gap-2">
                <Label for="secret_ref">{{ t('Secret reference') }}</Label>
                <Input
                    id="secret_ref"
                    v-model="form.secret_ref"
                    list="secret-refs"
                    placeholder="env:OPENAI_API_KEY"
                    class="font-mono"
                />
                <datalist id="secret-refs">
                    <option
                        v-for="ref in secretRefs"
                        :key="ref"
                        :value="`env:${ref}`"
                    />
                </datalist>
                <p class="text-xs text-muted-foreground">
                    {{
                        t(
                            'Only AI provider keys can be referenced (or variables starting with STARTER_AI_SECRET_).',
                        )
                    }}
                </p>
                <InputError :message="form.errors.secret_ref" />
            </div>

            <template v-for="key in driver?.credentials ?? []" :key="key">
                <div
                    v-if="
                        !(key === 'api_key' && form.credential_mode === 'env')
                    "
                    class="grid gap-2"
                >
                    <Label :for="key">{{
                        t(credentialLabels[key] ?? key)
                    }}</Label>
                    <Input
                        :id="key"
                        v-model="form[key as 'api_key']"
                        type="password"
                        autocomplete="off"
                        :placeholder="
                            connection?.masked_credentials[key] ??
                            (key === 'api_key' && driver?.requires_api_key
                                ? t('Required')
                                : t('Optional'))
                        "
                    />
                    <p
                        v-if="connection?.masked_credentials[key]"
                        class="text-xs text-muted-foreground"
                    >
                        {{
                            t(
                                'Leave empty to keep the current value. Enter a new one to rotate it.',
                            )
                        }}
                    </p>
                    <InputError :message="form.errors[key as 'api_key']" />
                </div>
            </template>
        </fieldset>

        <div class="grid gap-4 rounded-lg border p-4">
            <div class="flex items-center justify-between gap-4">
                <Label for="is_active">{{ t('Active') }}</Label>
                <Switch id="is_active" v-model="form.is_active" />
            </div>
            <div class="flex items-center justify-between gap-4">
                <Label for="is_default">{{ t('Default connection') }}</Label>
                <Switch id="is_default" v-model="form.is_default" />
            </div>
        </div>

        <div class="flex items-center gap-2">
            <Button
                type="submit"
                :disabled="form.processing"
                data-test="save-connection"
            >
                <Spinner v-if="form.processing" />
                {{ t('Save') }}
            </Button>
            <Button variant="ghost" as-child>
                <Link :href="connections.index()">{{ t('Cancel') }}</Link>
            </Button>
        </div>
    </form>
</template>
