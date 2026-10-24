<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import {
    BookOpenText,
    Bot,
    Brain,
    ChartColumn,
    FlaskConical,
    History,
    LayoutGrid,
    MessageSquareText,
    MonitorSmartphone,
    PiggyBank,
    Plug,
    Settings2,
    ShieldCheck,
    Sparkles,
    Tags,
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
import { recommender, usage } from '@/routes/admin/ai';
import { index as budgets } from '@/routes/admin/ai/budgets';
import { index as connections } from '@/routes/admin/ai/connections';
import { index as models } from '@/routes/admin/ai/models';
import { index as prices } from '@/routes/admin/ai/prices';
import { index as agents } from '@/routes/admin/ai/agents';
import { index as labIndex } from '@/routes/admin/ai/lab';
import { index as audit } from '@/routes/admin/audit';
import { index as roles } from '@/routes/admin/roles';
import { index as sessions } from '@/routes/admin/sessions';
import general from '@/routes/admin/settings/general';
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

    const systemSettings = can('settings.read') ? general.edit() : null;

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
                    can: 'users.read',
                },
                {
                    title: t('Roles & permissions'),
                    href: roles(),
                    icon: ShieldCheck,
                    can: 'roles.read',
                },
                {
                    title: t('Sessions'),
                    href: sessions(),
                    icon: MonitorSmartphone,
                    can: 'users.read',
                },
                {
                    title: t('Audit trail'),
                    href: audit(),
                    icon: History,
                    can: 'audit.read',
                },
                ...(systemSettings
                    ? [
                          {
                              title: t('System settings'),
                              href: systemSettings,
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
                    title: t('Connections'),
                    href: connections(),
                    icon: Plug,
                    can: 'ai.providers.manage',
                },
                {
                    title: t('Model catalog'),
                    href: models(),
                    icon: Brain,
                    can: 'ai.models.manage',
                },
                {
                    title: t('Prices'),
                    href: prices(),
                    icon: Tags,
                    can: 'ai.models.manage',
                },
                {
                    title: t('Model recommender'),
                    href: recommender(),
                    icon: Sparkles,
                    can: 'ai.models.manage',
                },
                {
                    title: t('Lab'),
                    href: labIndex(),
                    icon: FlaskConical,
                    can: 'ai.models.manage',
                },
                {
                    title: t('Agents'),
                    href: agents(),
                    icon: Bot,
                    can: 'ai.agents.manage',
                },
                {
                    title: t('Usage & costs'),
                    href: usage(),
                    icon: ChartColumn,
                    can: 'ai.usage.read',
                },
                {
                    title: t('Budgets'),
                    href: budgets(),
                    icon: PiggyBank,
                    can: 'ai.budgets.manage',
                },
            ]),
        },
    ].filter((section) => section.items.length > 0);
});

const footerNavItems = computed<NavItem[]>(() =>
    can('api.docs.read')
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
