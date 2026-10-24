<script setup lang="ts">
import type { InertiaLinkProps } from '@inertiajs/vue3';
import { router } from '@inertiajs/vue3';
import type { LucideIcon } from '@lucide/vue';
import { useEventListener } from '@vueuse/core';
import {
    CommandDialog,
    CommandEmpty,
    CommandGroup,
    CommandInput,
    CommandItem,
    CommandList,
    CommandSeparator,
    CommandShortcut,
} from '@starter/ui/components/command';
import { t } from '@/lib/i18n';
import { toUrl } from '@/lib/utils';

export type CommandEntry = {
    label: string;
    icon?: LucideIcon;
    href?: NonNullable<InertiaLinkProps['href']>;
    onSelect?: () => void;
    shortcut?: string;
};

export type CommandSection = { label: string; items: CommandEntry[] };

/**
 * Command palette (⌘K / Ctrl+K): search and jump to pages or run actions.
 * List only what the user is allowed to do.
 *
 * <CommandMenu v-model:open="open" :sections="[{ label: t('Pages'), items: [...] }]" />
 */
const props = withDefaults(
    defineProps<{
        sections: CommandSection[];
        /** Open with ⌘K / Ctrl+K. */
        hotkey?: boolean;
        placeholder?: string;
    }>(),
    { hotkey: true, placeholder: '' },
);

const open = defineModel<boolean>('open', { default: false });

useEventListener(document, 'keydown', (event: KeyboardEvent) => {
    if (
        props.hotkey &&
        event.key.toLowerCase() === 'k' &&
        (event.metaKey || event.ctrlKey)
    ) {
        event.preventDefault();
        open.value = !open.value;
    }
});

function choose(item: CommandEntry) {
    open.value = false;

    if (item.href) {
        router.visit(toUrl(item.href) ?? '');
    } else {
        item.onSelect?.();
    }
}
</script>

<template>
    <CommandDialog
        v-model:open="open"
        :title="t('Command palette')"
        :description="t('Search a page or an action')"
    >
        <CommandInput
            :placeholder="placeholder || t('Type a command or search…')"
        />
        <CommandList>
            <CommandEmpty>{{ t('No results.') }}</CommandEmpty>
            <template v-for="(section, index) in sections" :key="section.label">
                <CommandSeparator v-if="index > 0" />
                <CommandGroup :heading="section.label">
                    <CommandItem
                        v-for="item in section.items"
                        :key="item.label"
                        :value="`${section.label} ${item.label}`"
                        @select="choose(item)"
                    >
                        <component :is="item.icon" v-if="item.icon" />
                        {{ item.label }}
                        <CommandShortcut v-if="item.shortcut">{{
                            item.shortcut
                        }}</CommandShortcut>
                    </CommandItem>
                </CommandGroup>
            </template>
        </CommandList>
    </CommandDialog>
</template>
