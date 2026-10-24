<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import {
    Blocks,
    ChartColumn,
    Copy,
    FolderKanban,
    LayoutGrid,
    LogOut,
    Pencil,
    Settings,
    Share2,
    Trash2,
    User,
    Users,
} from '@lucide/vue';
import { ref } from 'vue';
import type { MenuAction } from '@/components/kit/ActionsMenu.vue';
import ActionsMenu from '@/components/kit/ActionsMenu.vue';
import type { CommandSection } from '@/components/kit/CommandMenu.vue';
import CommandMenu from '@/components/kit/CommandMenu.vue';
import Example from '@/components/ui-kit/Example.vue';
import { catalogSections } from '@/components/ui-kit/sections';
import {
    Breadcrumb,
    BreadcrumbItem,
    BreadcrumbLink,
    BreadcrumbList,
    BreadcrumbPage,
    BreadcrumbSeparator,
} from '@starter/ui/components/breadcrumb';
import { Button } from '@starter/ui/components/button';
import {
    ContextMenu,
    ContextMenuCheckboxItem,
    ContextMenuContent,
    ContextMenuItem,
    ContextMenuSeparator,
    ContextMenuShortcut,
    ContextMenuTrigger,
} from '@starter/ui/components/context-menu';
import {
    DropdownMenu,
    DropdownMenuCheckboxItem,
    DropdownMenuContent,
    DropdownMenuGroup,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuShortcut,
    DropdownMenuSub,
    DropdownMenuSubContent,
    DropdownMenuSubTrigger,
    DropdownMenuTrigger,
} from '@starter/ui/components/dropdown-menu';
import { Kbd, KbdGroup } from '@starter/ui/components/kbd';
import {
    Menubar,
    MenubarContent,
    MenubarItem,
    MenubarMenu,
    MenubarSeparator,
    MenubarShortcut,
    MenubarTrigger,
} from '@starter/ui/components/menubar';
import {
    Tabs,
    TabsContent,
    TabsList,
    TabsTrigger,
} from '@starter/ui/components/tabs';
import { t } from '@/lib/i18n';
import { notify } from '@/lib/notify';
import { dashboard } from '@/routes';
import { index, show } from '@/routes/ui-kit';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'UI kit', href: index() },
            { title: 'Menus and navigation', href: show('navigation') },
        ],
    },
});

const palette = ref(false);
const showGrid = ref(true);

const sections: CommandSection[] = [
    {
        label: t('Pages'),
        items: [
            { label: t('Dashboard'), icon: LayoutGrid, href: dashboard() },
            { label: t('UI kit'), icon: Blocks, href: index() },
        ],
    },
    {
        label: t('UI kit'),
        items: catalogSections.map((section) => ({
            label: t(section.title),
            icon: section.icon,
            href: show(section.key),
        })),
    },
    {
        label: t('Actions'),
        items: [
            {
                label: t('Show a toast'),
                icon: ChartColumn,
                onSelect: () =>
                    notify.success(t('Action run from the palette.')),
            },
        ],
    },
];

const projectActions: MenuAction[] = [
    { label: t('Edit'), icon: Pencil, onSelect: () => notify.info(t('Edit')) },
    {
        label: t('Share'),
        icon: Share2,
        onSelect: () => notify.info(t('Share')),
    },
    {
        label: t('Delete'),
        icon: Trash2,
        destructive: true,
        separated: true,
        confirm: {
            title: t('Delete project'),
            description: t(
                'The project will be deleted. This cannot be undone.',
            ),
        },
        onSelect: () => notify.success(t('Deleted (demo).')),
    },
];

const dropdownCode = `
<DropdownMenu>
    <DropdownMenuTrigger as-child><Button variant="outline">{{ t('Account') }}</Button></DropdownMenuTrigger>
    <DropdownMenuContent class="w-56">
        <DropdownMenuLabel>{{ t('My account') }}</DropdownMenuLabel>
        <DropdownMenuItem as-child><Link :href="edit()"><User /> {{ t('Profile') }}</Link></DropdownMenuItem>
        <DropdownMenuSeparator />
        <DropdownMenuItem variant="destructive" @select="logout"><LogOut /> {{ t('Log out') }}</DropdownMenuItem>
    </DropdownMenuContent>
</DropdownMenu>

<!-- Row actions with confirmation: -->
<ActionsMenu :items="[{ label: t('Edit'), icon: Pencil, href: edit(id) },
    { label: t('Delete'), icon: Trash2, destructive: true, confirm: { title: t('Delete?') }, onSelect: remove }]" />
`;

const paletteCode = `
<CommandMenu v-model:open="open" :sections="[
    { label: t('Pages'), items: [{ label: t('Dashboard'), icon: LayoutGrid, href: dashboard() }] },
    { label: t('Actions'), items: [{ label: t('New project'), icon: Plus, onSelect: create }] },
]" />
`;

const sidebarCode = `
// resources/js/modules/invoices/navigation.ts (picked up automatically)
import { Receipt } from '@lucide/vue';
import { index } from '@/routes/invoices';
import type { ModuleNavItem } from '@/types';

export default {
    title: 'Invoices',          // translated with t() in the sidebar
    href: index(),
    icon: Receipt,
    can: 'invoices.read',       // hidden without the permission
} satisfies ModuleNavItem;
`;
</script>

<template>
    <Head :title="t('Menus and navigation')" />

    <Example
        :title="t('Dropdown menu')"
        :description="
            t(
                'Actions of an object or of the account. ActionsMenu builds the row menu from a list and asks for confirmation when needed.',
            )
        "
        :code="dropdownCode"
    >
        <div class="flex flex-wrap items-center gap-3">
            <DropdownMenu>
                <DropdownMenuTrigger as-child>
                    <Button variant="outline">{{ t('Account') }}</Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent class="w-56" align="start">
                    <DropdownMenuLabel>{{ t('My account') }}</DropdownMenuLabel>
                    <DropdownMenuGroup>
                        <DropdownMenuItem>
                            <User /> {{ t('Profile') }}
                            <DropdownMenuShortcut>⇧⌘P</DropdownMenuShortcut>
                        </DropdownMenuItem>
                        <DropdownMenuItem>
                            <Settings /> {{ t('Settings') }}
                        </DropdownMenuItem>
                    </DropdownMenuGroup>
                    <DropdownMenuSeparator />
                    <DropdownMenuSub>
                        <DropdownMenuSubTrigger>
                            <Users class="mr-2 size-4 text-muted-foreground" />
                            {{ t('Invite users') }}
                        </DropdownMenuSubTrigger>
                        <DropdownMenuSubContent>
                            <DropdownMenuItem>{{
                                t('Email')
                            }}</DropdownMenuItem>
                            <DropdownMenuItem>{{ t('Link') }}</DropdownMenuItem>
                        </DropdownMenuSubContent>
                    </DropdownMenuSub>
                    <DropdownMenuCheckboxItem v-model="showGrid">
                        {{ t('Show grid') }}
                    </DropdownMenuCheckboxItem>
                    <DropdownMenuSeparator />
                    <DropdownMenuItem variant="destructive">
                        <LogOut /> {{ t('Log out') }}
                    </DropdownMenuItem>
                </DropdownMenuContent>
            </DropdownMenu>

            <div class="flex items-center gap-2 rounded-md border px-3 py-1.5">
                <FolderKanban class="size-4 text-muted-foreground" />
                <span class="text-sm">ERP-07</span>
                <ActionsMenu :items="projectActions" />
            </div>
        </div>
    </Example>

    <Example
        :title="t('Context menu and menubar')"
        :description="
            t(
                'Right click (or long press) the area; the menubar groups the commands of an editor.',
            )
        "
    >
        <div class="grid gap-4 lg:grid-cols-2">
            <ContextMenu>
                <ContextMenuTrigger
                    class="flex h-36 items-center justify-center rounded-lg border border-dashed text-sm text-muted-foreground"
                >
                    {{ t('Right click here') }}
                </ContextMenuTrigger>
                <ContextMenuContent class="w-52">
                    <ContextMenuItem>
                        <Copy /> {{ t('Copy') }}
                        <ContextMenuShortcut>⌘C</ContextMenuShortcut>
                    </ContextMenuItem>
                    <ContextMenuItem>
                        <Pencil /> {{ t('Rename') }}
                    </ContextMenuItem>
                    <ContextMenuSeparator />
                    <ContextMenuCheckboxItem v-model="showGrid">
                        {{ t('Show grid') }}
                    </ContextMenuCheckboxItem>
                    <ContextMenuSeparator />
                    <ContextMenuItem variant="destructive">
                        <Trash2 /> {{ t('Delete') }}
                    </ContextMenuItem>
                </ContextMenuContent>
            </ContextMenu>

            <div class="grid content-start gap-2">
                <Menubar class="w-fit">
                    <MenubarMenu>
                        <MenubarTrigger>{{ t('File') }}</MenubarTrigger>
                        <MenubarContent>
                            <MenubarItem>
                                {{ t('New') }}
                                <MenubarShortcut>⌘N</MenubarShortcut>
                            </MenubarItem>
                            <MenubarItem>{{ t('Open') }}</MenubarItem>
                            <MenubarSeparator />
                            <MenubarItem>{{ t('Export') }}</MenubarItem>
                        </MenubarContent>
                    </MenubarMenu>
                    <MenubarMenu>
                        <MenubarTrigger>{{ t('Edit') }}</MenubarTrigger>
                        <MenubarContent>
                            <MenubarItem>
                                {{ t('Undo') }}
                                <MenubarShortcut>⌘Z</MenubarShortcut>
                            </MenubarItem>
                            <MenubarItem>{{ t('Redo') }}</MenubarItem>
                        </MenubarContent>
                    </MenubarMenu>
                    <MenubarMenu>
                        <MenubarTrigger>{{ t('View') }}</MenubarTrigger>
                        <MenubarContent>
                            <MenubarItem>{{ t('Zoom in') }}</MenubarItem>
                            <MenubarItem>{{ t('Zoom out') }}</MenubarItem>
                        </MenubarContent>
                    </MenubarMenu>
                </Menubar>
            </div>
        </div>
    </Example>

    <Example
        :title="t('Command palette')"
        :description="
            t(
                'Jump to pages and run actions from the keyboard. List only what the user can do.',
            )
        "
        :code="paletteCode"
    >
        <div class="flex flex-wrap items-center gap-3">
            <Button variant="outline" @click="palette = true">
                {{ t('Open the palette') }}
                <KbdGroup class="ml-2"><Kbd>⌘</Kbd><Kbd>K</Kbd></KbdGroup>
            </Button>
            <CommandMenu v-model:open="palette" :sections="sections" />
        </div>
    </Example>

    <Example
        :title="t('Tabs and breadcrumbs')"
        :description="
            t(
                'Tabs split one page; breadcrumbs show where the page is (the layout fills them from defineOptions).',
            )
        "
    >
        <div class="grid gap-6">
            <Breadcrumb>
                <BreadcrumbList>
                    <BreadcrumbItem>
                        <BreadcrumbLink as-child>
                            <Link :href="dashboard()">{{
                                t('Dashboard')
                            }}</Link>
                        </BreadcrumbLink>
                    </BreadcrumbItem>
                    <BreadcrumbSeparator />
                    <BreadcrumbItem>
                        <BreadcrumbLink as-child>
                            <Link :href="index()">{{ t('UI kit') }}</Link>
                        </BreadcrumbLink>
                    </BreadcrumbItem>
                    <BreadcrumbSeparator />
                    <BreadcrumbItem>
                        <BreadcrumbPage>{{
                            t('Menus and navigation')
                        }}</BreadcrumbPage>
                    </BreadcrumbItem>
                </BreadcrumbList>
            </Breadcrumb>
            <Tabs default-value="overview" class="max-w-xl">
                <TabsList>
                    <TabsTrigger value="overview">{{
                        t('Overview')
                    }}</TabsTrigger>
                    <TabsTrigger value="activity">{{
                        t('Activity')
                    }}</TabsTrigger>
                    <TabsTrigger value="settings">{{
                        t('Settings')
                    }}</TabsTrigger>
                </TabsList>
                <TabsContent
                    value="overview"
                    class="text-sm text-muted-foreground"
                >
                    {{ t('Summary of the record.') }}
                </TabsContent>
                <TabsContent
                    value="activity"
                    class="text-sm text-muted-foreground"
                >
                    {{ t('Latest changes.') }}
                </TabsContent>
                <TabsContent
                    value="settings"
                    class="text-sm text-muted-foreground"
                >
                    {{ t('Options of the record.') }}
                </TabsContent>
            </Tabs>
        </div>
    </Example>

    <Example
        :title="t('Sidebar')"
        :description="
            t(
                'The app sidebar is the live example: collapse it with ⌘B / Ctrl+B. A module adds its entry with a navigation.ts file; entries with a permission are hidden from users without it.',
            )
        "
        :code="sidebarCode"
    >
        <div class="flex max-w-md overflow-hidden rounded-lg border">
            <div
                class="flex w-52 flex-col gap-1 border-r bg-sidebar p-2 text-sm"
            >
                <p
                    class="px-2 py-1.5 text-xs font-medium text-muted-foreground"
                >
                    {{ t('Platform') }}
                </p>
                <span
                    class="flex items-center gap-2 rounded-md bg-sidebar-accent px-2 py-1.5 font-medium text-sidebar-accent-foreground"
                >
                    <LayoutGrid class="size-4" /> {{ t('Dashboard') }}
                </span>
                <span class="flex items-center gap-2 rounded-md px-2 py-1.5">
                    <FolderKanban class="size-4" /> {{ t('Projects') }}
                    <span
                        class="ml-auto rounded bg-muted px-1.5 text-xs text-muted-foreground"
                        >12</span
                    >
                </span>
                <span class="flex items-center gap-2 rounded-md px-2 py-1.5">
                    <Users class="size-4" /> {{ t('Users') }}
                </span>
            </div>
            <div class="flex-1 p-4 text-sm text-muted-foreground">
                {{ t('Page content') }}
            </div>
        </div>
    </Example>
</template>
