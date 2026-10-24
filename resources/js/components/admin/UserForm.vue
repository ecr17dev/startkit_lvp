<script setup lang="ts">
import type { InertiaForm } from '@inertiajs/vue3';
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import InputError from '@/components/InputError.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import { Checkbox } from '@/components/ui/checkbox';
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

export type UserFormData = {
    name: string;
    email: string;
    password: string;
    is_active: boolean;
    email_verified: boolean;
    locale: string | null;
    roles: string[];
};

const props = defineProps<{
    form: InertiaForm<UserFormData>;
    roles: string[];
    editing?: boolean;
}>();

const page = usePage();
const locales = computed(() => page.props.locales ?? {});

const toggleRole = (role: string, checked: boolean | 'indeterminate') => {
    props.form.roles = checked
        ? [...new Set([...props.form.roles, role])]
        : props.form.roles.filter((item) => item !== role);
};
</script>

<template>
    <div class="grid gap-6">
        <div class="grid gap-6 sm:grid-cols-2">
            <div class="grid gap-2">
                <Label for="name">{{ t('Name') }}</Label>
                <Input
                    id="name"
                    v-model="form.name"
                    required
                    autocomplete="off"
                />
                <InputError :message="form.errors.name" />
            </div>
            <div class="grid gap-2">
                <Label for="email">{{ t('Email address') }}</Label>
                <Input
                    id="email"
                    v-model="form.email"
                    type="email"
                    required
                    autocomplete="off"
                />
                <InputError :message="form.errors.email" />
            </div>
        </div>

        <div class="grid gap-6 sm:grid-cols-2">
            <div class="grid gap-2">
                <Label for="password">{{
                    editing ? t('New password') : t('Password')
                }}</Label>
                <PasswordInput
                    id="password"
                    v-model="form.password"
                    autocomplete="new-password"
                    :placeholder="
                        editing
                            ? t('Leave blank to keep the current password')
                            : t('Optional')
                    "
                />
                <p v-if="!editing" class="text-xs text-muted-foreground">
                    {{
                        t(
                            'Without a password the user can log in with a code sent by email.',
                        )
                    }}
                </p>
                <InputError :message="form.errors.password" />
            </div>
            <div class="grid gap-2">
                <Label>{{ t('Language') }}</Label>
                <Select
                    :model-value="form.locale ?? 'default'"
                    @update:model-value="
                        (value) =>
                            (form.locale =
                                value === 'default' ? null : String(value))
                    "
                >
                    <SelectTrigger class="w-full">
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value="default">{{
                            t('Default of the application')
                        }}</SelectItem>
                        <SelectItem
                            v-for="(label, code) in locales"
                            :key="code"
                            :value="code"
                            >{{ label }}</SelectItem
                        >
                    </SelectContent>
                </Select>
                <InputError :message="form.errors.locale" />
            </div>
        </div>

        <div class="grid gap-3">
            <Label>{{ t('Roles') }}</Label>
            <div class="grid gap-2 sm:grid-cols-3">
                <Label
                    v-for="role in roles"
                    :key="role"
                    class="flex items-center gap-2 rounded-md border p-3 font-normal"
                >
                    <Checkbox
                        :model-value="form.roles.includes(role)"
                        @update:model-value="
                            (checked) => toggleRole(role, checked)
                        "
                    />
                    <span class="font-medium">{{ role }}</span>
                </Label>
            </div>
            <InputError :message="form.errors.roles" />
        </div>

        <div class="grid gap-4 rounded-lg border p-4">
            <div class="flex items-center justify-between gap-4">
                <div>
                    <Label for="is_active">{{ t('Active account') }}</Label>
                    <p class="text-xs text-muted-foreground">
                        {{
                            t(
                                'Inactive users cannot log in and their sessions are closed.',
                            )
                        }}
                    </p>
                </div>
                <Switch id="is_active" v-model="form.is_active" />
            </div>
            <InputError :message="form.errors.is_active" />
            <div class="flex items-center justify-between gap-4">
                <div>
                    <Label for="email_verified">{{
                        t('Email verified')
                    }}</Label>
                    <p class="text-xs text-muted-foreground">
                        {{
                            t(
                                'Mark the email address as verified without sending a verification link.',
                            )
                        }}
                    </p>
                </div>
                <Switch id="email_verified" v-model="form.email_verified" />
            </div>
        </div>
    </div>
</template>
