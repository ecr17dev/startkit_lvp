<script setup lang="ts">
import type { InertiaForm } from '@inertiajs/vue3';
import { useTemplateRef } from 'vue';
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
    ai_model_id: number | string;
    temperature: number | string;
    max_tokens: number | string;
    is_default: boolean;
    is_active: boolean;
};

export type PromptModelOption = {
    id: number;
    label: string;
    connection: string;
};

const props = defineProps<{
    form: InertiaForm<PromptFormData>;
    models: PromptModelOption[];
    variables: string[];
}>();

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

        <div class="grid gap-2">
            <Label>{{ t('Model') }}</Label>
            <Select
                :model-value="String(form.ai_model_id || 'default')"
                @update:model-value="
                    (value) =>
                        (form.ai_model_id =
                            value === 'default' ? '' : Number(value))
                "
            >
                <SelectTrigger class="w-full">
                    <SelectValue />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem value="default">{{
                        t('Default model of the catalog')
                    }}</SelectItem>
                    <SelectItem
                        v-for="model in models"
                        :key="model.id"
                        :value="String(model.id)"
                        >{{ model.label }} · {{ model.connection }}</SelectItem
                    >
                </SelectContent>
            </Select>
            <p class="text-xs text-muted-foreground">
                {{
                    t(
                        'Only enabled models of active connections are listed (Admin → AI → Models).',
                    )
                }}
            </p>
            <InputError :message="form.errors.ai_model_id" />
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
