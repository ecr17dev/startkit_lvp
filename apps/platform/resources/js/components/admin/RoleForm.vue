<script setup lang="ts">
import type { InertiaForm } from '@inertiajs/vue3';
import InputError from '@/components/InputError.vue';
import { Checkbox } from '@starter/ui/components/checkbox';
import { Input } from '@starter/ui/components/input';
import { Label } from '@starter/ui/components/label';
import { t } from '@/lib/i18n';
import type { PermissionGroup } from '@/types';

export type RoleFormData = {
    name: string;
    permissions: string[];
};

const props = defineProps<{
    form: InertiaForm<RoleFormData>;
    permissionGroups: PermissionGroup[];
    nameLocked?: boolean;
}>();

const groupLabels: Record<string, string> = {
    users: 'Users',
    roles: 'Roles',
    settings: 'System settings',
    ai: 'Artificial intelligence',
    api: 'API',
};

const toggle = (permission: string, checked: boolean | 'indeterminate') => {
    props.form.permissions = checked
        ? [...new Set([...props.form.permissions, permission])]
        : props.form.permissions.filter((item) => item !== permission);
};

const groupState = (group: PermissionGroup): boolean | 'indeterminate' => {
    const selected = group.permissions.filter((p) =>
        props.form.permissions.includes(p.name),
    ).length;

    if (selected === 0) {
        return false;
    }

    return selected === group.permissions.length ? true : 'indeterminate';
};

const toggleGroup = (
    group: PermissionGroup,
    checked: boolean | 'indeterminate',
) => {
    const names = group.permissions.map((p) => p.name);

    props.form.permissions = checked
        ? [...new Set([...props.form.permissions, ...names])]
        : props.form.permissions.filter((item) => !names.includes(item));
};
</script>

<template>
    <div class="grid gap-6">
        <div class="grid max-w-sm gap-2">
            <Label for="name">{{ t('Role name') }}</Label>
            <Input
                id="name"
                v-model="form.name"
                required
                :disabled="nameLocked"
                placeholder="editor"
            />
            <p class="text-xs text-muted-foreground">
                {{
                    nameLocked
                        ? t('System roles cannot be renamed.')
                        : t('Lowercase letters, numbers and dashes.')
                }}
            </p>
            <InputError :message="form.errors.name" />
        </div>

        <div class="grid gap-4">
            <Label>{{ t('Permissions') }}</Label>
            <div class="grid gap-4 md:grid-cols-2">
                <div
                    v-for="group in permissionGroups"
                    :key="group.group"
                    class="rounded-lg border"
                >
                    <Label
                        class="flex items-center gap-2 border-b bg-muted/50 px-4 py-3"
                    >
                        <Checkbox
                            :model-value="groupState(group)"
                            @update:model-value="
                                (checked) => toggleGroup(group, checked)
                            "
                        />
                        <span class="font-semibold">{{
                            t(groupLabels[group.group] ?? group.group)
                        }}</span>
                    </Label>
                    <div class="grid gap-3 p-4">
                        <Label
                            v-for="permission in group.permissions"
                            :key="permission.name"
                            class="flex items-start gap-2 font-normal"
                        >
                            <Checkbox
                                :model-value="
                                    form.permissions.includes(permission.name)
                                "
                                @update:model-value="
                                    (checked) =>
                                        toggle(permission.name, checked)
                                "
                            />
                            <span class="grid gap-0.5">
                                <span>{{ permission.description }}</span>
                                <code class="text-xs text-muted-foreground">{{
                                    permission.name
                                }}</code>
                            </span>
                        </Label>
                    </div>
                </div>
            </div>
            <InputError :message="form.errors.permissions" />
        </div>
    </div>
</template>
