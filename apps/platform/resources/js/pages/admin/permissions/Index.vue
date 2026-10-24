<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { Lock, Save } from '@lucide/vue';
import { computed, reactive, ref } from 'vue';
import PageHeader from '@/components/PageHeader.vue';
import { Button } from '@starter/ui/components/button';
import { Checkbox } from '@starter/ui/components/checkbox';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@starter/ui/components/table';
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@starter/ui/components/tooltip';
import { t } from '@/lib/i18n';
import { permissionGroupLabels } from '@/lib/permissions';
import { index as rolesIndex } from '@/routes/admin/roles';
import { index, update } from '@/routes/admin/permissions';
import type { AdminRole, PermissionGroup } from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Roles & permissions', href: rolesIndex() },
            { title: 'Permission matrix', href: index() },
        ],
    },
});

const props = defineProps<{
    roles: AdminRole[];
    permissionGroups: PermissionGroup[];
    grantable: string[];
    editable: string[];
}>();

// Working copy of the matrix: role name => permission names...
const matrix = reactive<Record<string, string[]>>(
    Object.fromEntries(
        props.roles.map((role) => [role.name, [...(role.permissions ?? [])]]),
    ),
);
const saving = ref<string | null>(null);

const dirty = computed(() =>
    props.roles
        .filter((role) => {
            const original = [...(role.permissions ?? [])].sort().join();
            return [...matrix[role.name]].sort().join() !== original;
        })
        .map((role) => role.name),
);

const canEdit = (role: AdminRole, permission: string) =>
    props.editable.includes(role.name) && props.grantable.includes(permission);

const toggle = (
    role: AdminRole,
    permission: string,
    checked: boolean | 'indeterminate',
) => {
    matrix[role.name] = checked
        ? [...new Set([...matrix[role.name], permission])]
        : matrix[role.name].filter((item) => item !== permission);
};

const save = (role: AdminRole) => {
    saving.value = role.name;
    router.put(
        update(role.id).url,
        { permissions: matrix[role.name] },
        {
            preserveScroll: true,
            onFinish: () => (saving.value = null),
        },
    );
};
</script>

<template>
    <Head :title="t('Permission matrix')" />

    <div class="flex flex-col gap-6 p-4 md:p-6">
        <PageHeader
            :title="t('Permission matrix')"
            :description="
                t(
                    'Every role against every permission. Owners always hold every permission; you can only change permissions you hold.',
                )
            "
        />

        <TooltipProvider>
            <div class="overflow-x-auto rounded-lg border">
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead class="min-w-64">{{
                                t('Permission')
                            }}</TableHead>
                            <TableHead
                                v-for="role in roles"
                                :key="role.id"
                                class="min-w-24 text-center"
                            >
                                <div class="flex flex-col items-center gap-1">
                                    <span class="flex items-center gap-1">
                                        <Lock
                                            v-if="!editable.includes(role.name)"
                                            class="size-3"
                                        />
                                        {{ role.name }}
                                    </span>
                                    <Button
                                        v-if="dirty.includes(role.name)"
                                        size="sm"
                                        class="h-6 px-2 text-xs"
                                        :disabled="saving === role.name"
                                        :data-test="`save-${role.name}`"
                                        @click="save(role)"
                                    >
                                        <Save class="size-3" />
                                        {{ t('Save') }}
                                    </Button>
                                </div>
                            </TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        <template
                            v-for="group in permissionGroups"
                            :key="group.group"
                        >
                            <TableRow class="bg-muted/50 hover:bg-muted/50">
                                <TableCell
                                    :colspan="roles.length + 1"
                                    class="text-xs font-semibold text-muted-foreground uppercase"
                                >
                                    {{
                                        t(
                                            permissionGroupLabels[
                                                group.group
                                            ] ?? group.group,
                                        )
                                    }}
                                </TableCell>
                            </TableRow>
                            <TableRow
                                v-for="permission in group.permissions"
                                :key="permission.name"
                            >
                                <TableCell>
                                    <div class="text-sm">
                                        {{ permission.description }}
                                    </div>
                                    <code
                                        class="text-xs text-muted-foreground"
                                        >{{ permission.name }}</code
                                    >
                                </TableCell>
                                <TableCell
                                    v-for="role in roles"
                                    :key="role.id"
                                    class="text-center"
                                >
                                    <Tooltip
                                        :disabled="
                                            canEdit(role, permission.name)
                                        "
                                    >
                                        <TooltipTrigger as-child>
                                            <span class="inline-flex">
                                                <Checkbox
                                                    :model-value="
                                                        matrix[
                                                            role.name
                                                        ].includes(
                                                            permission.name,
                                                        )
                                                    "
                                                    :disabled="
                                                        !canEdit(
                                                            role,
                                                            permission.name,
                                                        )
                                                    "
                                                    :aria-label="`${role.name}: ${permission.name}`"
                                                    @update:model-value="
                                                        (checked) =>
                                                            toggle(
                                                                role,
                                                                permission.name,
                                                                checked,
                                                            )
                                                    "
                                                />
                                            </span>
                                        </TooltipTrigger>
                                        <TooltipContent>
                                            {{
                                                editable.includes(role.name)
                                                    ? t(
                                                          'You do not hold this permission.',
                                                      )
                                                    : t(
                                                          'You cannot edit this role.',
                                                      )
                                            }}
                                        </TooltipContent>
                                    </Tooltip>
                                </TableCell>
                            </TableRow>
                        </template>
                    </TableBody>
                </Table>
            </div>
        </TooltipProvider>
    </div>
</template>
