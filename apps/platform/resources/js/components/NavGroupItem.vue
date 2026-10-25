<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ChevronRight } from '@lucide/vue';
import { useStorage } from '@vueuse/core';
import { computed, watch } from 'vue';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@starter/ui/components/collapsible';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@starter/ui/components/dropdown-menu';
import {
    SidebarMenuButton,
    SidebarMenuItem,
    SidebarMenuSub,
    SidebarMenuSubButton,
    SidebarMenuSubItem,
    useSidebar,
} from '@starter/ui/components/sidebar';
import { useCurrentUrl } from '@/composables/useCurrentUrl';
import type { NavGroup, NavItem } from '@/types';

const props = defineProps<{
    group: NavGroup;
}>();

const { isCurrentOrParentUrl } = useCurrentUrl();
const { state, isMobile, setOpenMobile } = useSidebar();

const isActive = (item: NavItem) =>
    item.isActive ?? isCurrentOrParentUrl(item.href);

const active = computed(() => props.group.items.some(isActive));

// Open groups are remembered per browser; a group opens by itself when
// one of its pages is visited...
const remembered = useStorage<Record<string, boolean>>(
    'starter:sidebar-groups',
    {},
);

const open = computed({
    get: () => remembered.value[props.group.id] ?? active.value,
    set: (value: boolean) => {
        remembered.value = { ...remembered.value, [props.group.id]: value };
    },
});

watch(
    active,
    (now) => {
        if (now && !open.value) {
            open.value = true;
        }
    },
    { immediate: true },
);

// With only icons visible, the group is a dropdown next to the sidebar...
const iconsOnly = computed(
    () => state.value === 'collapsed' && !isMobile.value,
);

// On phones the sidebar is a sheet: close it once a page is chosen...
const close = () => {
    if (isMobile.value) {
        setOpenMobile(false);
    }
};
</script>

<template>
    <SidebarMenuItem v-if="iconsOnly">
        <DropdownMenu>
            <DropdownMenuTrigger as-child>
                <SidebarMenuButton
                    :is-active="active"
                    :aria-label="group.title"
                    :data-test="`nav-group-${group.id}`"
                >
                    <component :is="group.icon" />
                    <span>{{ group.title }}</span>
                </SidebarMenuButton>
            </DropdownMenuTrigger>
            <DropdownMenuContent side="right" align="start" class="min-w-52">
                <DropdownMenuLabel>{{ group.title }}</DropdownMenuLabel>
                <DropdownMenuSeparator />
                <DropdownMenuItem
                    v-for="item in group.items"
                    :key="item.title"
                    as-child
                    :class="isActive(item) ? 'bg-accent font-medium' : ''"
                >
                    <Link :href="item.href">
                        <component :is="item.icon" v-if="item.icon" />
                        {{ item.title }}
                    </Link>
                </DropdownMenuItem>
            </DropdownMenuContent>
        </DropdownMenu>
    </SidebarMenuItem>

    <Collapsible v-else v-model:open="open" as-child class="group/collapsible">
        <SidebarMenuItem>
            <CollapsibleTrigger as-child>
                <SidebarMenuButton
                    :is-active="active && !open"
                    :tooltip="group.title"
                    :data-test="`nav-group-${group.id}`"
                >
                    <component :is="group.icon" />
                    <span class="truncate">{{ group.title }}</span>
                    <ChevronRight
                        class="ml-auto transition-transform duration-200 group-data-[state=open]/collapsible:rotate-90"
                    />
                </SidebarMenuButton>
            </CollapsibleTrigger>
            <CollapsibleContent
                class="overflow-hidden data-[state=closed]:animate-collapsible-up data-[state=open]:animate-collapsible-down"
            >
                <SidebarMenuSub>
                    <SidebarMenuSubItem
                        v-for="item in group.items"
                        :key="item.title"
                    >
                        <SidebarMenuSubButton
                            as-child
                            :is-active="isActive(item)"
                        >
                            <Link :href="item.href" @click="close">
                                <span>{{ item.title }}</span>
                            </Link>
                        </SidebarMenuSubButton>
                    </SidebarMenuSubItem>
                </SidebarMenuSub>
            </CollapsibleContent>
        </SidebarMenuItem>
    </Collapsible>
</template>
