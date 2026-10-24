<script setup lang="ts">
import { Plus, X } from '@lucide/vue';
import { ref } from 'vue';
import { Badge } from '@starter/ui/components/badge';
import { Button } from '@starter/ui/components/button';
import { Input } from '@starter/ui/components/input';
import { t } from '@/lib/i18n';
import { principalLabel } from '@/lib/knowledge';

const model = defineModel<string[]>({ required: true });

withDefaults(defineProps<{ allowEmpty?: boolean; id?: string }>(), {
    allowEmpty: false,
    id: 'acl',
});

const draft = ref('');

const add = (value?: string) => {
    const principal = (value ?? draft.value).trim();

    if (principal && !model.value.includes(principal)) {
        model.value = [...model.value, principal];
    }

    draft.value = '';
};

const remove = (principal: string) =>
    (model.value = model.value.filter((item) => item !== principal));
</script>

<template>
    <div class="grid gap-2">
        <div class="flex flex-wrap gap-1.5">
            <Badge
                v-for="principal in model"
                :key="principal"
                variant="secondary"
                class="gap-1"
            >
                {{ principalLabel(principal) }}
                <button
                    type="button"
                    class="rounded hover:text-destructive"
                    :aria-label="t('Remove')"
                    @click="remove(principal)"
                >
                    <X class="size-3" />
                </button>
            </Badge>
            <span v-if="!model.length" class="text-xs text-muted-foreground">{{
                allowEmpty
                    ? t('Inherits the collection access list.')
                    : t('Nobody has access.')
            }}</span>
        </div>
        <div class="flex gap-2">
            <Input
                :id="id"
                v-model="draft"
                placeholder="everyone · role:editor · user:12 · permission:rag.documents.read"
                @keydown.enter.prevent="add()"
            />
            <Button type="button" variant="outline" @click="add()">
                <Plus class="size-4" />
                {{ t('Add') }}
            </Button>
        </div>
        <div class="flex flex-wrap gap-1">
            <Button
                v-for="suggestion in [
                    'everyone',
                    'role:admin',
                    'role:editor',
                    'role:reader',
                ]"
                :key="suggestion"
                type="button"
                size="sm"
                variant="ghost"
                class="h-6 px-2 text-xs"
                :disabled="model.includes(suggestion)"
                @click="add(suggestion)"
                >+ {{ principalLabel(suggestion) }}</Button
            >
        </div>
    </div>
</template>
