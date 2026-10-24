<script setup lang="ts">
import type { InertiaForm } from '@inertiajs/vue3';
import InputError from '@/components/InputError.vue';
import { Input } from '@starter/ui/components/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@starter/ui/components/select';
import { Switch } from '@starter/ui/components/switch';
import { Textarea } from '@starter/ui/components/textarea';
import { Label } from '@starter/ui/components/label';
import { t } from '@/lib/i18n';
import type { ProjectFormData, ProjectOptions } from './types';

defineProps<{
    form: InertiaForm<ProjectFormData>;
    options: ProjectOptions;
}>();
</script>

<template>
    <div class="grid gap-6">
        <div class="grid gap-2">
            <Label for="name">{{ t('Name') }}</Label>
            <Input id="name" v-model="form.name" maxlength="150" required />
            <InputError :message="form.errors.name" />
        </div>
        <div class="grid gap-2">
            <Label for="code">{{ t('Code') }}</Label>
            <Input id="code" v-model="form.code" maxlength="20" required />
            <InputError :message="form.errors.code" />
        </div>
        <div class="grid gap-2">
            <Label for="description">{{ t('Description') }}</Label>
            <Textarea id="description" v-model="form.description" rows="4" />
            <InputError :message="form.errors.description" />
        </div>
        <div class="grid gap-2">
            <Label for="status">{{ t('Status') }}</Label>
            <Select v-model="form.status">
                <SelectTrigger id="status" class="w-full sm:w-64">
                    <SelectValue :placeholder="t('Select')" />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem
                        v-for="option in options.status"
                        :key="option.value"
                        :value="option.value"
                        >{{ option.label }}</SelectItem
                    >
                </SelectContent>
            </Select>
            <InputError :message="form.errors.status" />
        </div>
        <div class="grid gap-2">
            <Label for="starts-on">{{ t('Start date') }}</Label>
            <Input
                id="starts-on"
                v-model="form.starts_on"
                type="date"
                class="w-full sm:w-48"
            />
            <InputError :message="form.errors.starts_on" />
        </div>
        <div class="grid gap-2">
            <Label for="due-on">{{ t('Due date') }}</Label>
            <Input
                id="due-on"
                v-model="form.due_on"
                type="date"
                class="w-full sm:w-48"
            />
            <InputError :message="form.errors.due_on" />
        </div>
        <div class="grid gap-2">
            <Label for="budget">{{ t('Budget') }}</Label>
            <Input
                id="budget"
                v-model="form.budget"
                type="number"
                step="0.01"
            />
            <InputError :message="form.errors.budget" />
        </div>
        <div class="flex items-center gap-3">
            <Switch id="is-billable" v-model="form.is_billable" />
            <Label for="is-billable">{{ t('Billable') }}</Label>
            <InputError :message="form.errors.is_billable" />
        </div>
    </div>
</template>
