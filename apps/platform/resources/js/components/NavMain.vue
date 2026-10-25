<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import NavGroupItem from '@/components/NavGroupItem.vue';
import {
    SidebarGroup,
    SidebarGroupLabel,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
    useSidebar,
} from '@starter/ui/components/sidebar';
import { useCurrentUrl } from '@/composables/useCurrentUrl';
import type { NavEntry, NavGroup } from '@/types';

defineProps<{
    label?: string;
    items: NavEntry[];
}>();

const { isCurrentOrParentUrl } = useCurrentUrl();

const isGroup = (entry: NavEntry): entry is NavGroup => 'items' in entry;

// On phones the sidebar is a sheet: close it once a page is chosen...
const { isMobile, setOpenMobile } = useSidebar();
const close = () => {
    if (isMobile.value) {
        setOpenMobile(false);
    }
};
</script>

<template>
    <SidebarGroup class="px-2 py-0">
        <SidebarGroupLabel v-if="label">{{ label }}</SidebarGroupLabel>
        <SidebarMenu>
            <template v-for="entry in items" :key="entry.title">
                <NavGroupItem v-if="isGroup(entry)" :group="entry" />
                <SidebarMenuItem v-else>
                    <SidebarMenuButton
                        as-child
                        :is-active="
                            entry.isActive ?? isCurrentOrParentUrl(entry.href)
                        "
                        :tooltip="entry.title"
                    >
                        <Link :href="entry.href" @click="close">
                            <component :is="entry.icon" />
                            <span>{{ entry.title }}</span>
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </template>
        </SidebarMenu>
    </SidebarGroup>
</template>
