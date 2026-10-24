<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { watch } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@starter/ui/components/button';
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
import { Textarea } from '@starter/ui/components/textarea';
import { t } from '@/lib/i18n';
import evaluations from '@/routes/admin/knowledge/evaluations';

const props = defineProps<{
    collections: { id: number; name: string }[];
    set?: {
        id: number;
        name: string;
        description: string | null;
        collection_ids: number[];
    } | null;
}>();

const open = defineModel<boolean>('open', { default: false });

const form = useForm({
    name: '',
    description: '',
    collection_ids: [] as number[],
});

watch(open, (value) => {
    if (!value) {
        return;
    }

    form.clearErrors();
    form.name = props.set?.name ?? '';
    form.description = props.set?.description ?? '';
    form.collection_ids = [...(props.set?.collection_ids ?? [])];
});

const toggle = (id: number, checked: boolean | string) => {
    form.collection_ids = checked
        ? [...new Set([...form.collection_ids, id])]
        : form.collection_ids.filter((item) => item !== id);
};

const submit = () => {
    const options = {
        preserveScroll: true,
        onSuccess: () => (open.value = false),
    };

    if (props.set) {
        form.put(evaluations.update(props.set.id).url, options);
    } else {
        form.post(evaluations.store().url, options);
    }
};
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="sm:max-w-lg">
            <DialogHeader>
                <DialogTitle>{{
                    set ? t('Edit evaluation set') : t('New evaluation set')
                }}</DialogTitle>
                <DialogDescription>{{
                    t(
                        'Fixed questions with the documents that should be found. Runs measure recall@k, MRR and hit rate with different retrieval settings.',
                    )
                }}</DialogDescription>
            </DialogHeader>
            <form
                id="evaluation-set"
                class="grid gap-4"
                @submit.prevent="submit"
            >
                <div class="grid gap-2">
                    <Label for="set-name">{{ t('Name') }}</Label>
                    <Input
                        id="set-name"
                        v-model="form.name"
                        required
                        data-test="set-name"
                    />
                    <InputError :message="form.errors.name" />
                </div>
                <div class="grid gap-2">
                    <Label for="set-description">{{ t('Description') }}</Label>
                    <Textarea
                        id="set-description"
                        v-model="form.description"
                        rows="2"
                    />
                    <InputError :message="form.errors.description" />
                </div>
                <div class="grid gap-2">
                    <Label>{{ t('Collections') }}</Label>
                    <Label
                        v-for="collection in collections"
                        :key="collection.id"
                        class="flex items-center gap-2 text-sm font-normal"
                    >
                        <Checkbox
                            :model-value="
                                form.collection_ids.includes(collection.id)
                            "
                            @update:model-value="
                                (checked) => toggle(collection.id, checked)
                            "
                        />
                        {{ collection.name }}
                    </Label>
                    <InputError :message="form.errors.collection_ids" />
                </div>
            </form>
            <DialogFooter>
                <Button variant="outline" @click="open = false">{{
                    t('Cancel')
                }}</Button>
                <Button
                    type="submit"
                    form="evaluation-set"
                    :disabled="form.processing || !form.collection_ids.length"
                    data-test="set-submit"
                    >{{ t('Save') }}</Button
                >
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
