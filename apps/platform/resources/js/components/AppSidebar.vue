<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import {
    BookOpenText,
    Bot,
    Brain,
    ChartColumn,
    LayoutGrid,
    MessageSquareText,
    Settings2,
    ShieldCheck,
    Users,
} from '@lucide/vue';
import { computed } from 'vue';
import AppLogo from '@/components/AppLogo.vue';
import NavFooter from '@/components/NavFooter.vue';
import NavMain from '@/components/NavMain.vue';
import NavUser from '@/components/NavUser.vue';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@starter/ui/components/sidebar';
import { useCurrentUrl } from '@/composables/useCurrentUrl';
import { usePermissions } from '@/composables/usePermissions';
import { t } from '@/lib/i18n';
import { dashboard } from '@/routes';
import { chat } from '@/routes/ai';
import { usage } from '@/routes/admin/ai';
import { index as prompts } from '@/routes/admin/ai/prompts';
import { index as providers } from '@/routes/admin/ai/providers';
import { index as roles } from '@/routes/admin/roles';
import authSettings from '@/routes/admin/settings/auth';
import branding from '@/routes/admin/settings/branding';
import general from '@/routes/admin/settings/general';
import mail from '@/routes/admin/settings/mail';
import { index as users } from '@/routes/admin/users';
import { ui as apiDocs } from '@/routes/scramble/docs';
import type { NavItem } from '@/types';

const { can } = usePermissions();
const { currentUrl } = useCurrentUrl();

type NavSection = {
    label: string;
    items: NavItem[];
};

const sections = computed<NavSection[]>(() => {
    const filter = (items: (NavItem & { can?: string | string[] })[]) =>
        items.filter((item) => !item.can || can(item.can));

    const systemSettings = [
        { permission: 'settings.general', href: general.edit() },
        { permission: 'settings.branding', href: branding.edit() },
        { permission: 'settings.mail', href: mail.edit() },
        { permission: 'settings.auth', href: authSettings.edit() },
    ].find((item) => can(item.permission));

    return [
        {
            label: t('Platform'),
            items: filter([
                { title: t('Dashboard'), href: dashboard(), icon: LayoutGrid },
                {
                    title: t('AI chat'),
                    href: chat(),
                    icon: MessageSquareText,
                    can: 'ai.chat',
                },
            ]),
        },
        {
            label: t('Administration'),
            items: filter([
                {
                    title: t('Users'),
                    href: users(),
                    icon: Users,
                    can: 'users.view',
                },
                {
                    title: t('Roles & permissions'),
                    href: roles(),
                    icon: ShieldCheck,
                    can: 'roles.view',
                },
                ...(systemSettings
                    ? [
                          {
                              title: t('System settings'),
                              href: systemSettings.href,
                              icon: Settings2,
                              isActive:
                                  currentUrl.value.startsWith(
                                      '/admin/settings',
                                  ),
                          },
                      ]
                    : []),
            ]),
        },
        {
            label: t('Artificial intelligence'),
            items: filter([
                {
                    title: t('Providers & models'),
                    href: providers(),
                    icon: Brain,
                    can: 'ai.providers.manage',
                },
                {
                    title: t('System prompts'),
                    href: prompts(),
                    icon: Bot,
                    can: 'ai.prompts.manage',
                },
                {
                    title: t('Usage & costs'),
                    href: usage(),
                    icon: ChartColumn,
                    can: 'ai.usage.view',
                },
            ]),
        },
    ].filter((section) => section.items.length > 0);
});

const footerNavItems = computed<NavItem[]>(() =>
    can('api.docs')
        ? [
              {
                  title: t('API documentation'),
                  href: apiDocs(),
                  icon: BookOpenText,
              },
          ]
        : [],
);
</script>

<template>
    <Sidebar collapsible="icon" variant="inset">
        <SidebarHeader>
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton size="lg" as-child>
                        <Link :href="dashboard()">
                            <AppLogo />
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
        </SidebarHeader>

        <SidebarContent>
            <NavMain
                v-for="section in sections"
                :key="section.label"
                :label="section.label"
                :items="section.items"
            />
        </SidebarContent>

        <SidebarFooter>
            <NavFooter v-if="footerNavItems.length" :items="footerNavItems" />
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>
