<script setup lang="ts">
import type { InertiaLinkProps } from '@inertiajs/vue3';
import { Link } from '@inertiajs/vue3';
import type { LucideIcon } from '@lucide/vue';
import { MoreHorizontal } from '@lucide/vue';
import { ref } from 'vue';
import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from '@starter/ui/components/alert-dialog';
import { Button, buttonVariants } from '@starter/ui/components/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuShortcut,
    DropdownMenuTrigger,
} from '@starter/ui/components/dropdown-menu';
import { t } from '@/lib/i18n';

export type MenuAction = {
    label: string;
    icon?: LucideIcon;
    /** Navigate with Inertia (a route of Wayfinder or a URL). */
    href?: NonNullable<InertiaLinkProps['href']>;
    onSelect?: () => void;
    destructive?: boolean;
    disabled?: boolean;
    shortcut?: string;
    /** Ask first: title (and description) of the confirmation. */
    confirm?: { title: string; description?: string; action?: string };
    /** Draw a separator before this item. */
    separated?: boolean;
};

/**
 * "⋯" menu of a row or a card. Hide the items the user cannot run (the
 * backend checks the permission again anyway).
 *
 * <ActionsMenu :items="[
 *     { label: t('Edit'), icon: Pencil, href: edit(project.id) },
 *     { label: t('Delete'), icon: Trash2, destructive: true, separated: true,
 *       confirm: { title: t('Delete project') }, onSelect: () => remove(project) },
 * ]" />
 */
withDefaults(
    defineProps<{
        items: MenuAction[];
        label?: string;
        align?: 'start' | 'end';
    }>(),
    { label: '', align: 'end' },
);

const pending = ref<MenuAction | null>(null);

function run(item: MenuAction) {
    if (item.confirm) {
        pending.value = item;

        return;
    }

    item.onSelect?.();
}

function confirmPending() {
    pending.value?.onSelect?.();
    pending.value = null;
}
</script>

<template>
    <DropdownMenu>
        <DropdownMenuTrigger as-child>
            <Button
                variant="ghost"
                size="icon-sm"
                :aria-label="label || t('Actions')"
            >
                <MoreHorizontal class="size-4" />
            </Button>
        </DropdownMenuTrigger>
        <DropdownMenuContent :align="align" class="min-w-44">
            <DropdownMenuLabel v-if="label">{{ label }}</DropdownMenuLabel>
            <template v-for="item in items" :key="item.label">
                <DropdownMenuSeparator v-if="item.separated" />
                <DropdownMenuItem
                    v-if="item.href && !item.disabled"
                    as-child
                    :variant="item.destructive ? 'destructive' : 'default'"
                >
                    <Link :href="item.href">
                        <component :is="item.icon" v-if="item.icon" />
                        {{ item.label }}
                        <DropdownMenuShortcut v-if="item.shortcut">{{
                            item.shortcut
                        }}</DropdownMenuShortcut>
                    </Link>
                </DropdownMenuItem>
                <DropdownMenuItem
                    v-else
                    :disabled="item.disabled"
                    :variant="item.destructive ? 'destructive' : 'default'"
                    @select="run(item)"
                >
                    <component :is="item.icon" v-if="item.icon" />
                    {{ item.label }}
                    <DropdownMenuShortcut v-if="item.shortcut">{{
                        item.shortcut
                    }}</DropdownMenuShortcut>
                </DropdownMenuItem>
            </template>
        </DropdownMenuContent>
    </DropdownMenu>

    <AlertDialog
        :open="pending !== null"
        @update:open="(open) => !open && (pending = null)"
    >
        <AlertDialogContent>
            <AlertDialogHeader>
                <AlertDialogTitle>{{
                    pending?.confirm?.title
                }}</AlertDialogTitle>
                <AlertDialogDescription v-if="pending?.confirm?.description">
                    {{ pending.confirm.description }}
                </AlertDialogDescription>
            </AlertDialogHeader>
            <AlertDialogFooter>
                <AlertDialogCancel>{{ t('Cancel') }}</AlertDialogCancel>
                <AlertDialogAction
                    :class="
                        buttonVariants({
                            variant: pending?.destructive
                                ? 'destructive'
                                : 'default',
                        })
                    "
                    @click="confirmPending"
                >
                    {{ pending?.confirm?.action || pending?.label }}
                </AlertDialogAction>
            </AlertDialogFooter>
        </AlertDialogContent>
    </AlertDialog>
</template>
