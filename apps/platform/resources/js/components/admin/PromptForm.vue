<script setup lang="ts">
import type { InertiaForm } from '@inertiajs/vue3';
import { computed, useTemplateRef } from 'vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@starter/ui/components/badge';
import { Input } from '@starter/ui/components/input';
import { Label } from '@starter/ui/components/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@starter/ui/components/select';
import { Switch } from '@starter/ui/components/switch';
import { Textarea } from '@starter/ui/components/textarea';
import { t } from '@/lib/i18n';

export type PromptFormData = {
    name: string;
    slug: string;
    description: string;
    content: string;
    ai_provider_id: number | string;
    model: string;
    temperature: number | string;
    max_tokens: number | string;
    is_default: boolean;
    is_active: boolean;
};

export type PromptProviderOption = {
    id: number;
    label: string;
    default_model: string | null;
    models: { model: string; label: string | null }[];
};

const props = defineProps<{
    form: InertiaForm<PromptFormData>;
    providers: PromptProviderOption[];
    variables: string[];
}>();

const selectedProvider = computed(() =>
    props.providers.find(
        (provider) => String(provider.id) === String(props.form.ai_provider_id),
    ),
);

const content = useTemplateRef<{ $el: HTMLTextAreaElement }>('content');

const token = (variable: string) => `{{${variable}}}`;

const insertVariable = (variable: string) => {
    const value = token(variable);
    const element = content.value?.$el;

    if (!element) {
        props.form.content += value;

        return;
    }

    const start = element.selectionStart ?? props.form.content.length;
    const end = element.selectionEnd ?? start;
    props.form.content =
        props.form.content.slice(0, start) +
        value +
        props.form.content.slice(end);
};
</script>

<template>
    <div class="grid gap-6">
        <div class="grid gap-6 sm:grid-cols-2">
            <div class="grid gap-2">
                <Label for="name">{{ t('Name') }}</Label>
                <Input id="name" v-model="form.name" required />
                <InputError :message="form.errors.name" />
            </div>
            <div class="grid gap-2">
                <Label for="slug">{{ t('Identifier') }}</Label>
                <Input
                    id="slug"
                    v-model="form.slug"
                    class="font-mono"
                    :placeholder="t('Generated from the name')"
                />
                <InputError :message="form.errors.slug" />
            </div>
        </div>

        <div class="grid gap-2">
            <Label for="description">{{ t('Description') }}</Label>
            <Input id="description" v-model="form.description" />
            <InputError :message="form.errors.description" />
        </div>

        <div class="grid gap-2">
            <Label for="content">{{ t('System prompt') }}</Label>
            <Textarea
                id="content"
                ref="content"
                v-model="form.content"
                rows="12"
                class="font-mono text-sm"
                required
            />
            <div class="flex flex-wrap items-center gap-1">
                <span class="text-xs text-muted-foreground"
                    >{{ t('Variables') }}:</span
                >
                <button
                    v-for="variable in variables"
                    :key="variable"
                    type="button"
                    @click="insertVariable(variable)"
                >
                    <Badge
                        variant="secondary"
                        class="cursor-pointer font-mono"
                        >{{ token(variable) }}</Badge
                    >
                </button>
            </div>
            <InputError :message="form.errors.content" />
        </div>

        <div class="grid gap-6 sm:grid-cols-2">
            <div class="grid gap-2">
                <Label>{{ t('Provider') }}</Label>
                <Select
                    :model-value="String(form.ai_provider_id || 'default')"
                    @update:model-value="
                        (value) =>
                            (form.ai_provider_id =
                                value === 'default' ? '' : Number(value))
                    "
                >
                    <SelectTrigger class="w-full">
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value="default">{{
                            t('Default provider')
                        }}</SelectItem>
                        <SelectItem
                            v-for="provider in providers"
                            :key="provider.id"
                            :value="String(provider.id)"
                            >{{ provider.label }}</SelectItem
                        >
                    </SelectContent>
                </Select>
                <InputError :message="form.errors.ai_provider_id" />
            </div>
            <div class="grid gap-2">
                <Label for="model">{{ t('Model') }}</Label>
                <Input
                    id="model"
                    v-model="form.model"
                    list="prompt-models"
                    class="font-mono"
                    :disabled="!form.ai_provider_id"
                    :placeholder="
                        selectedProvider?.default_model ??
                        t('Default model of the provider')
                    "
                />
                <datalist id="prompt-models">
                    <option
                        v-for="model in selectedProvider?.models ?? []"
                        :key="model.model"
                        :value="model.model"
                    >
                        {{ model.label }}
                    </option>
                </datalist>
                <InputError :message="form.errors.model" />
            </div>
        </div>

        <div class="grid gap-6 sm:grid-cols-2">
            <div class="grid gap-2">
                <Label for="temperature"
                    >{{ t('Temperature') }} ({{ t('optional') }})</Label
                >
                <Input
                    id="temperature"
                    v-model="form.temperature"
                    type="number"
                    min="0"
                    max="2"
                    step="0.1"
                />
                <p class="text-xs text-muted-foreground">
                    {{
                        t(
                            'Leave empty to use the model default. Some recent models ignore or reject it.',
                        )
                    }}
                </p>
                <InputError :message="form.errors.temperature" />
            </div>
            <div class="grid gap-2">
                <Label for="max_tokens"
                    >{{ t('Max output tokens') }} ({{ t('optional') }})</Label
                >
                <Input
                    id="max_tokens"
                    v-model="form.max_tokens"
                    type="number"
                    min="1"
                />
                <InputError :message="form.errors.max_tokens" />
            </div>
        </div>

        <div class="grid gap-4 rounded-lg border p-4">
            <div class="flex items-center justify-between gap-4">
                <div>
                    <Label for="is_active">{{ t('Active') }}</Label>
                    <p class="text-xs text-muted-foreground">
                        {{ t('Available in the chat and the API.') }}
                    </p>
                </div>
                <Switch id="is_active" v-model="form.is_active" />
            </div>
            <div class="flex items-center justify-between gap-4">
                <div>
                    <Label for="is_default">{{ t('Default prompt') }}</Label>
                    <p class="text-xs text-muted-foreground">
                        {{ t('Used when no prompt is selected.') }}
                    </p>
                </div>
                <Switch id="is_default" v-model="form.is_default" />
            </div>
        </div>
    </div>
</template>
