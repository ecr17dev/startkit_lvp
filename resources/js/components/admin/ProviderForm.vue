<script setup lang="ts">
import type { InertiaForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import InputError from '@/components/InputError.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Switch } from '@/components/ui/switch';
import { t } from '@/lib/i18n';
import type { ProviderDriverOption } from '@/types';

export type ProviderFormData = {
    label: string;
    name: string;
    driver: string;
    api_key: string;
    base_url: string;
    default_model: string;
    is_active: boolean;
    is_default: boolean;
    import_models: boolean;
    options: {
        region: string;
        access_key_id: string;
        secret_access_key: string;
        session_token: string;
        api_version: string;
        deployment: string;
    };
};

const props = defineProps<{
    form: InertiaForm<ProviderFormData>;
    drivers: ProviderDriverOption[];
    editing?: boolean;
    apiKeyMasked?: string | null;
    secrets?: Record<string, boolean>;
}>();

const driver = computed(() =>
    props.drivers.find((item) => item.value === props.form.driver),
);
const has = (field: string) => driver.value?.fields.includes(field) ?? false;
const required = (field: string) =>
    driver.value?.required.includes(field) ?? false;

const secretPlaceholder = (saved: boolean | undefined) =>
    saved ? t('Saved. Leave blank to keep it') : '';
</script>

<template>
    <div class="grid gap-6">
        <div class="grid gap-6 sm:grid-cols-2">
            <div class="grid gap-2">
                <Label>{{ t('Provider') }}</Label>
                <Select v-model="form.driver">
                    <SelectTrigger class="w-full">
                        <SelectValue :placeholder="t('Select a provider')" />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem
                            v-for="option in drivers"
                            :key="option.value"
                            :value="option.value"
                            >{{ option.label }}</SelectItem
                        >
                    </SelectContent>
                </Select>
                <InputError :message="form.errors.driver" />
            </div>
            <div class="grid gap-2">
                <Label for="label">{{ t('Display name') }}</Label>
                <Input
                    id="label"
                    v-model="form.label"
                    :placeholder="driver?.label ?? 'OpenAI'"
                    required
                />
                <InputError :message="form.errors.label" />
            </div>
        </div>

        <div class="grid gap-2 sm:w-1/2">
            <Label for="name">{{ t('Identifier') }}</Label>
            <Input
                id="name"
                v-model="form.name"
                placeholder="openai"
                class="font-mono"
            />
            <p class="text-xs text-muted-foreground">
                {{
                    t(
                        'Used in code and in the API: provider: ":name". Generated from the display name when empty.',
                        { name: form.name || 'openai' },
                    )
                }}
            </p>
            <InputError :message="form.errors.name" />
        </div>

        <template v-if="driver">
            <div v-if="has('region')" class="grid gap-2 sm:w-1/2">
                <Label for="region">{{ t('AWS region') }}</Label>
                <Input
                    id="region"
                    v-model="form.options.region"
                    placeholder="us-east-1"
                />
                <InputError :message="form.errors['options.region']" />
            </div>

            <div v-if="has('api_key')" class="grid gap-2">
                <Label for="api_key">
                    {{
                        driver?.value === 'bedrock'
                            ? t('Bedrock API key (bearer token)')
                            : t('API key')
                    }}
                    <span
                        v-if="!required('api_key')"
                        class="text-muted-foreground"
                        >({{ t('optional') }})</span
                    >
                </Label>
                <PasswordInput
                    id="api_key"
                    v-model="form.api_key"
                    autocomplete="off"
                    :placeholder="
                        editing && apiKeyMasked
                            ? `${apiKeyMasked} — ${t('Leave blank to keep it')}`
                            : ''
                    "
                />
                <InputError :message="form.errors.api_key" />
            </div>

            <template v-if="has('access_key_id')">
                <p class="text-xs text-muted-foreground">
                    {{
                        t(
                            'Use an API key, IAM access keys, or leave both empty to use the default AWS credential chain (instance role, environment variables).',
                        )
                    }}
                </p>
                <div class="grid gap-6 sm:grid-cols-2">
                    <div class="grid gap-2">
                        <Label for="access_key_id">{{
                            t('Access key ID')
                        }}</Label>
                        <Input
                            id="access_key_id"
                            v-model="form.options.access_key_id"
                            autocomplete="off"
                        />
                        <InputError
                            :message="form.errors['options.access_key_id']"
                        />
                    </div>
                    <div class="grid gap-2">
                        <Label for="secret_access_key">{{
                            t('Secret access key')
                        }}</Label>
                        <PasswordInput
                            id="secret_access_key"
                            v-model="form.options.secret_access_key"
                            autocomplete="off"
                            :placeholder="
                                secretPlaceholder(
                                    secrets?.has_secret_access_key,
                                )
                            "
                        />
                        <InputError
                            :message="form.errors['options.secret_access_key']"
                        />
                    </div>
                </div>
                <div class="grid gap-2">
                    <Label for="session_token"
                        >{{ t('Session token') }} ({{ t('optional') }})</Label
                    >
                    <PasswordInput
                        id="session_token"
                        v-model="form.options.session_token"
                        autocomplete="off"
                        :placeholder="
                            secretPlaceholder(secrets?.has_session_token)
                        "
                    />
                </div>
            </template>

            <div v-if="has('base_url')" class="grid gap-2">
                <Label for="base_url">
                    {{ t('Base URL') }}
                    <span
                        v-if="!required('base_url')"
                        class="text-muted-foreground"
                        >({{ t('optional') }})</span
                    >
                </Label>
                <Input
                    id="base_url"
                    v-model="form.base_url"
                    type="url"
                    :placeholder="
                        driver.default_url ?? 'https://api.example.com/v1'
                    "
                />
                <p
                    v-if="driver.value === 'openai-compatible'"
                    class="text-xs text-muted-foreground"
                >
                    {{
                        t(
                            'Any OpenAI compatible endpoint: vLLM, LM Studio, LiteLLM, Together, Fireworks, Azure AI Foundry, etc.',
                        )
                    }}
                </p>
                <InputError :message="form.errors.base_url" />
            </div>

            <div v-if="has('api_version')" class="grid gap-6 sm:grid-cols-2">
                <div class="grid gap-2">
                    <Label for="api_version">{{ t('API version') }}</Label>
                    <Input
                        id="api_version"
                        v-model="form.options.api_version"
                        placeholder="2025-04-01-preview"
                    />
                </div>
                <div class="grid gap-2">
                    <Label for="deployment">{{ t('Deployment') }}</Label>
                    <Input id="deployment" v-model="form.options.deployment" />
                    <InputError :message="form.errors['options.deployment']" />
                </div>
            </div>

            <div class="grid gap-2 sm:w-1/2">
                <Label for="default_model">
                    {{ t('Default model') }}
                    <span
                        v-if="!driver?.requires_model"
                        class="text-muted-foreground"
                        >({{ t('optional') }})</span
                    >
                </Label>
                <Input
                    id="default_model"
                    v-model="form.default_model"
                    class="font-mono"
                />
                <InputError :message="form.errors.default_model" />
            </div>
        </template>

        <div class="grid gap-4 rounded-lg border p-4">
            <div class="flex items-center justify-between gap-4">
                <div>
                    <Label for="is_active">{{ t('Active') }}</Label>
                    <p class="text-xs text-muted-foreground">
                        {{ t('Inactive providers cannot be used.') }}
                    </p>
                </div>
                <Switch id="is_active" v-model="form.is_active" />
            </div>
            <div class="flex items-center justify-between gap-4">
                <div>
                    <Label for="is_default">{{ t('Default provider') }}</Label>
                    <p class="text-xs text-muted-foreground">
                        {{ t('Used when no provider is specified.') }}
                    </p>
                </div>
                <Switch id="is_default" v-model="form.is_default" />
            </div>
            <div
                v-if="!editing"
                class="flex items-center justify-between gap-4"
            >
                <div>
                    <Label for="import_models">{{
                        t('Import suggested models')
                    }}</Label>
                    <p class="text-xs text-muted-foreground">
                        {{
                            t(
                                'Adds the popular models of the provider to the pricing table.',
                            )
                        }}
                    </p>
                </div>
                <Switch id="import_models" v-model="form.import_models" />
            </div>
        </div>
    </div>
</template>
