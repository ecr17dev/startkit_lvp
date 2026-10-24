<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { watch } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { Switch } from '@/components/ui/switch';
import { t } from '@/lib/i18n';
import {
    store as storeModel,
    update as updateModel,
} from '@/routes/admin/ai/models';
import type { AiModelData } from '@/types';

const open = defineModel<boolean>('open', { default: false });

const props = defineProps<{
    providerId: number;
    model?: AiModelData | null;
}>();

const blank = () => ({
    model: '',
    label: '',
    input_price: '' as string | number,
    output_price: '' as string | number,
    cache_read_price: '' as string | number,
    cache_write_price: '' as string | number,
    context_window: '' as string | number,
    is_active: true,
});

const form = useForm(blank());

watch(open, (value) => {
    if (!value) {
        return;
    }

    form.clearErrors();
    form.defaults(
        props.model
            ? {
                  model: props.model.model,
                  label: props.model.label ?? '',
                  input_price: props.model.input_price ?? '',
                  output_price: props.model.output_price ?? '',
                  cache_read_price: props.model.cache_read_price ?? '',
                  cache_write_price: props.model.cache_write_price ?? '',
                  context_window: props.model.context_window ?? '',
                  is_active: props.model.is_active,
              }
            : blank(),
    );
    form.reset();
});

const submit = () => {
    const nullable = (value: string | number) => (value === '' ? null : value);

    form.transform((data) => ({
        ...data,
        label: data.label || null,
        input_price: nullable(data.input_price),
        output_price: nullable(data.output_price),
        cache_read_price: nullable(data.cache_read_price),
        cache_write_price: nullable(data.cache_write_price),
        context_window: nullable(data.context_window),
    }));

    const options = {
        preserveScroll: true,
        onSuccess: () => (open.value = false),
    };

    if (props.model) {
        form.put(updateModel(props.model.id).url, options);
    } else {
        form.post(storeModel(props.providerId).url, options);
    }
};

const priceFields = [
    { key: 'input_price', label: 'Input' },
    { key: 'output_price', label: 'Output' },
    { key: 'cache_read_price', label: 'Cache read' },
    { key: 'cache_write_price', label: 'Cache write' },
] as const;
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="sm:max-w-lg">
            <form class="grid gap-5" @submit.prevent="submit">
                <DialogHeader>
                    <DialogTitle>{{
                        model ? t('Edit model') : t('Add model')
                    }}</DialogTitle>
                    <DialogDescription>
                        {{
                            t(
                                'Prices in USD per million tokens. They are used to calculate costs.',
                            )
                        }}
                    </DialogDescription>
                </DialogHeader>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="grid gap-2">
                        <Label for="model">{{ t('Model ID') }}</Label>
                        <Input
                            id="model"
                            v-model="form.model"
                            class="font-mono"
                            required
                        />
                        <InputError :message="form.errors.model" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="model-label">{{ t('Label') }}</Label>
                        <Input id="model-label" v-model="form.label" />
                        <InputError :message="form.errors.label" />
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-4 sm:grid-cols-4">
                    <div
                        v-for="field in priceFields"
                        :key="field.key"
                        class="grid gap-2"
                    >
                        <Label :for="field.key" class="text-xs">{{
                            t(field.label)
                        }}</Label>
                        <Input
                            :id="field.key"
                            v-model="form[field.key]"
                            type="number"
                            step="0.000001"
                            min="0"
                            placeholder="0.00"
                        />
                        <InputError :message="form.errors[field.key]" />
                    </div>
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="grid gap-2">
                        <Label for="context_window">{{
                            t('Context window (tokens)')
                        }}</Label>
                        <Input
                            id="context_window"
                            v-model="form.context_window"
                            type="number"
                            min="1"
                        />
                        <InputError :message="form.errors.context_window" />
                    </div>
                    <div
                        class="flex items-center justify-between gap-2 self-end rounded-md border p-2"
                    >
                        <Label for="model-active">{{
                            t('Available in the chat')
                        }}</Label>
                        <Switch id="model-active" v-model="form.is_active" />
                    </div>
                </div>

                <DialogFooter>
                    <Button
                        type="button"
                        variant="ghost"
                        @click="open = false"
                        >{{ t('Cancel') }}</Button
                    >
                    <Button type="submit" :disabled="form.processing">
                        <Spinner v-if="form.processing" />
                        {{ t('Save') }}
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
