<script setup lang="ts">
import type { InertiaForm } from '@inertiajs/vue3';
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import InputError from '@/components/InputError.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import { Checkbox } from '@starter/ui/components/checkbox';
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
import { t } from '@/lib/i18n';

export type UserFormData = {
    name: string;
    email: string;
    password: string;
    email_verified: boolean;
    locale: string | null;
    roles: string[];
};

const props = withDefaults(
    defineProps<{
        form: InertiaForm<UserFormData>;
        /** Roles the current user can grant. */
        roles: string[];
        editing?: boolean;
        passwordsEnabled?: boolean;
        canAssignRoles?: boolean;
        disabled?: boolean;
    }>(),
    { canAssignRoles: true },
);

const page = usePage();
const locales = computed(() => page.props.locales ?? {});

// Roles the user already has but the current user cannot grant are shown
// read only: the server rejects changes to them anyway...
const roleOptions = computed(() => [
    ...new Set([...props.roles, ...props.form.roles]),
]);

const toggleRole = (role: string, checked: boolean | 'indeterminate') => {
    props.form.roles = checked
        ? [...new Set([...props.form.roles, role])]
        : props.form.roles.filter((item) => item !== role);
};
</script>

<template>
    <fieldset class="grid gap-6" :disabled="disabled">
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
            <div v-if="passwordsEnabled" class="grid gap-2">
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

        <p class="text-xs text-muted-foreground">
            {{
                t(
                    'Users log in with a one-time code sent to their email address.',
                )
            }}
        </p>

        <div class="grid gap-3">
            <Label>{{ t('Roles') }}</Label>
            <p v-if="!canAssignRoles" class="text-xs text-muted-foreground">
                {{ t('You cannot change the roles of this account.') }}
            </p>
            <div class="grid gap-2 sm:grid-cols-3">
                <Label
                    v-for="role in roleOptions"
                    :key="role"
                    class="flex items-center gap-2 rounded-md border p-3 font-normal has-disabled:opacity-60"
                >
                    <Checkbox
                        :model-value="form.roles.includes(role)"
                        :disabled="!canAssignRoles || !roles.includes(role)"
                        @update:model-value="
                            (checked) => toggleRole(role, checked)
                        "
                    />
                    <span class="font-medium">{{ role }}</span>
                </Label>
            </div>
            <InputError :message="form.errors.roles" />
        </div>

        <div
            class="flex items-center justify-between gap-4 rounded-lg border p-4"
        >
            <div>
                <Label for="email_verified">{{ t('Email verified') }}</Label>
                <p class="text-xs text-muted-foreground">
                    {{
                        t(
                            'Otherwise the address is verified with the first one-time code.',
                        )
                    }}
                </p>
            </div>
            <Switch id="email_verified" v-model="form.email_verified" />
        </div>
    </fieldset>
</template>
